<?php

namespace App\Services\Ders;

use App\Domain\Ders\KatilimciDurumu;
use App\Domain\Ders\YayinAraligi;
use App\Exceptions\GecersizIslemException;
use App\Models\DersKatilimci;
use App\Models\DersOturumu;
use App\Models\DersProgramiSablonu;
use App\Models\Salonlar;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Haftalik ders programi: sablon CRUD + "Programi Yayinla" (sablondan ileriye oturum uretimi,
 * sablon_id+tarih ile idempotent). Sablon duzenlenince ondan uretilmis GELECEK oturumlar da
 * senkronlanir; silinince katilimcisiz gelecek oturumlar iptal edilir.
 *
 * Not (bilinen, eskiyle ayni): iptal edilmis oturum yeniden yayinda uretilmez.
 */
class DersProgramServisi
{
    public function __construct(
        private DersReferansServisi $referans,
        private DersKatilimServisi $katilim,
    ) {}

    public function program(int $salonId): array
    {
        return $this->referans->secenekler($salonId) + ['sablon' => $this->sablonlar($salonId)];
    }

    /** @return array<int,array<string,mixed>> */
    public function sablonlar(int $salonId): array
    {
        return DersProgramiSablonu::with('personel:id,personel_adi')->where('salon_id', $salonId)->where('aktif', 1)
            ->orderBy('hafta_gunu')->orderBy('saat')->get()
            ->map(fn (DersProgramiSablonu $s) => [
                'id' => (int) $s->id, 'hafta_gunu' => (int) $s->hafta_gunu, 'ders_tipi' => $s->ders_tipi,
                'hizmet_id' => $s->hizmet_id, 'personel_id' => $s->personel_id, 'personel' => $s->personel?->personel_adi,
                'saat' => substr((string) $s->saat, 0, 5), 'saat_bitis' => substr((string) $s->saat_bitis, 0, 5),
                'kapasite' => (int) $s->kapasite, 'renk' => $s->renk,
            ])->all();
    }

    /** $terfi=false: mobilin bugunku davranisi, kapasite artisi bekleyeni terfi ettirmez. */
    public function sablonKaydet(int $salonId, ?int $sablonId, array $g, ?int $yetkiliId, bool $terfi = true): array
    {
        $this->referans->personelHizmetDogrula($salonId, $g['personel_id'] ?? null, $g['hizmet_id'] ?? null);
        $alan = [
            'salon_id' => $salonId, 'sube_id' => $salonId, 'personel_id' => $g['personel_id'] ?? null,
            'hafta_gunu' => (int) $g['hafta_gunu'], 'saat' => $g['saat'], 'saat_bitis' => $g['saat_bitis'],
            'ders_tipi' => ($g['ders_tipi'] ?? null) ?: DersOturumu::VARSAYILAN_AD, 'hizmet_id' => $g['hizmet_id'] ?? null,
            'kapasite' => (int) $g['kapasite'], 'aktif' => true,
        ] + array_intersect_key($g, array_flip(['renk'])); // gonderilmezse mevcut deger korunur

        return DB::transaction(function () use ($salonId, $sablonId, $alan, $yetkiliId, $terfi) {
            $senkron = 0;
            if ($sablonId) {
                $s = DersProgramiSablonu::where('salon_id', $salonId)->findOrFail($sablonId);
                $s->update($alan);
                $senkron = $this->gelecekOturumlar($salonId, $s->id)->update(array_intersect_key($alan, array_flip(
                    ['hizmet_id', 'ders_tipi', 'personel_id', 'saat', 'saat_bitis', 'kapasite', 'renk'],
                )));
                if ($terfi) {
                    $this->katilim->terfiler($this->gelecekOturumlar($salonId, $s->id)->pluck('id')->all(), $yetkiliId);
                }
            } else {
                $s = DersProgramiSablonu::create($alan);
            }
            AktiviteLog::yaz($salonId, $yetkiliId, $sablonId ? 'ders_sablon_guncelle' : 'ders_sablon_ekle', 'ders_sablon', (int) $s->id, $s->ders_tipi);

            return ['sablon_id' => (int) $s->id, 'senkron' => $senkron];
        });
    }

    /** Sablonu siler; katilimcisiz gelecek oturumlari iptal eder, katilimcisi olanlara dokunmaz. */
    public function sablonSil(int $salonId, int $sablonId, ?int $yetkiliId): array
    {
        $s = DersProgramiSablonu::where('salon_id', $salonId)->findOrFail($sablonId);

        return DB::transaction(function () use ($salonId, $s, $yetkiliId) {
            $temizlenen = 0;
            $korunan = 0;
            foreach ($this->gelecekOturumlar($salonId, $s->id)->get() as $o) {
                $dolu = DersKatilimci::where('oturum_id', $o->id)->where('durum', '!=', KatilimciDurumu::IPTAL->value)->exists();
                if ($dolu) {
                    $korunan++;
                } else {
                    $o->update(['aktif' => false, 'iptal' => true]);
                    $temizlenen++;
                }
            }
            $s->delete();
            AktiviteLog::yaz($salonId, $yetkiliId, 'ders_sablon_sil', 'ders_sablon', (int) $s->id, $s->ders_tipi);

            return ['temizlenen' => $temizlenen, 'korunan' => $korunan];
        });
    }

    /** $renkKopyala=false: mobilin bugunku davranisi, oturum sablon rengini almaz. */
    public function yayinla(int $salonId, string $baslangic, ?string $bitis, ?int $hafta, ?int $yetkiliId, bool $renkKopyala = true): array
    {
        try {
            $aralik = YayinAraligi::olustur($baslangic, $bitis, $hafta, Salonlar::studyoModu($salonId));
        } catch (InvalidArgumentException $e) {
            throw new GecersizIslemException($e->getMessage());
        }

        return DB::transaction(function () use ($salonId, $aralik, $yetkiliId, $renkKopyala) {
            // Kilit: es zamanli iki yayin ayni tarihleri iki kez uretmesin.
            $sablonlar = DersProgramiSablonu::where('salon_id', $salonId)->where('aktif', 1)->lockForUpdate()->get();
            if ($sablonlar->isEmpty()) {
                throw new GecersizIslemException('Önce haftalık programa ders ekleyin.');
            }
            $mevcut = DersOturumu::where('salon_id', $salonId)->whereIn('sablon_id', $sablonlar->pluck('id'))
                ->where('tarih', '>=', $aralik->baslangicTarihi())
                ->get(['sablon_id', 'tarih'])->mapWithKeys(fn ($o) => [$o->sablon_id.'|'.$o->tarih => true]);

            $olusan = 0;
            $atlanan = 0;
            foreach ($sablonlar as $s) {
                foreach ($aralik->tarihler((int) $s->hafta_gunu) as $tarih) {
                    if (isset($mevcut[$s->id.'|'.$tarih])) {
                        $atlanan++;

                        continue;
                    }
                    $this->oturumUret($s, $tarih, $renkKopyala);
                    $olusan++;
                }
            }
            AktiviteLog::yaz($salonId, $yetkiliId, 'ders_programi_yayinla', 'ders_sablon', null, "{$olusan} ders", null, ['atlanan' => $atlanan, 'hafta' => $aralik->hafta]);

            return ['olusan' => $olusan, 'atlanan' => $atlanan, 'hafta' => $aralik->hafta];
        });
    }

    /** Sablon + tarihten oturum (yayin ve otomatik ders plani ORTAK). */
    public function oturumUret(DersProgramiSablonu $s, string $tarih, bool $renkKopyala = true): DersOturumu
    {
        return DersOturumu::create([
            'salon_id' => $s->salon_id, 'sube_id' => $s->sube_id, 'personel_id' => $s->personel_id, 'ders_tipi' => $s->ders_tipi,
            'hizmet_id' => $s->hizmet_id, 'tarih' => $tarih, 'saat' => $s->saat, 'saat_bitis' => $s->saat_bitis,
            'kapasite' => $s->kapasite, 'sablon_id' => $s->id, 'renk' => $renkKopyala ? $s->renk : null, 'aktif' => true,
        ]);
    }

    private function gelecekOturumlar(int $salonId, int $sablonId)
    {
        return DersOturumu::yayinda()->where('salon_id', $salonId)->where('sablon_id', $sablonId)->where('tarih', '>=', date('Y-m-d'));
    }
}
