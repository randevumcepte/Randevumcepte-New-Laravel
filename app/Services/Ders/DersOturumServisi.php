<?php

namespace App\Services\Ders;

use App\Domain\Ders\KatilimciDurumu;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Models\DersKatilimci;
use App\Models\DersOturumu;
use App\Models\Personeller;
use App\Support\AktiviteLog;
use App\Support\KvkkMaske;
use Illuminate\Support\Facades\DB;

/**
 * Ders oturumu: liste (gun/aralik), detay + katilimcilar, olustur/guncelle, iptal, takvim olaylari.
 * Cikti alanlari Flutter GrupDersOturum / GrupDersKatilimci modelleriyle ayni.
 */
class DersOturumServisi
{
    public function __construct(
        private DersSeansServisi $seans,
        private DersBildirim $bildirim,
        private DersReferansServisi $referans,
        private DersKatilimServisi $katilim,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function liste(int $salonId, string $tarih1, string $tarih2, ?int $personelId): array
    {
        return $this->sorgu($salonId, $tarih1, $tarih2, $personelId)
            ->with('personel:id,personel_adi')->withCount('kontenjandakiler as doluluk')->get()
            ->map(fn (DersOturumu $o) => $this->sunum($o) + ['doluluk' => (int) $o->doluluk])->all();
    }

    /** Takvim blogu icin: katilimci adlariyla. resourceId = personel (yalniz personel takviminde anlamli). */
    public function takvimOlaylari(int $salonId, string $tarih1, string $tarih2, ?int $personelId): array
    {
        return $this->sorgu($salonId, $tarih1, $tarih2, $personelId)
            ->with(['kontenjandakiler.musteri:id,name'])->get()
            ->map(function (DersOturumu $o) {
                $adlar = $o->kontenjandakiler->map(fn ($k) => trim((string) $k->musteri?->name))->filter()->values()->all();

                return [
                    'id' => 'ders-'.$o->id, 'ders_oturum_id' => (int) $o->id, 'ders_tipi' => $o->ad(),
                    'start' => $o->tarih.'T'.$this->saat($o->saat).':00', 'end' => $o->tarih.'T'.$this->saat($o->saat_bitis).':00',
                    'resourceId' => $o->personel_id, 'renk' => $o->renk,
                    'kapasite' => (int) $o->kapasite, 'doluluk' => count($adlar), 'katilimcilar' => $adlar,
                ];
            })->all();
    }

    public function detay(int $oturumId, bool $telGor): array
    {
        $o = DersOturumu::with(['personel:id,personel_adi', 'katilimcilar.musteri:id,name,cep_telefon'])->find($oturumId)
            ?? throw new BulunamadiException('Ders bulunamadı');
        $salonId = (int) $o->salon_id;

        return [
            'oturum' => $this->sunum($o) + [
                'iptal'   => (int) $o->iptal,
                'doluluk' => $o->katilimcilar->filter(fn (DersKatilimci $k) => $k->durumu()->kontenjanTutar())->count(),
            ],
            'katilimcilar' => $o->katilimcilar->where('durum', '!=', KatilimciDurumu::IPTAL->value)->values()->map(fn (DersKatilimci $k) => [
                'id'          => (int) $k->id,
                'user_id'     => (int) $k->user_id,
                'ad'          => $k->musteri?->name ?? '#'.$k->user_id,
                'tel'         => $telGor ? (string) $k->musteri?->cep_telefon : KvkkMaske::telefon($k->musteri?->cep_telefon),
                'durum'       => $k->durum,
                'hak_dusuldu' => (int) $k->hak_dusuldu,
                // Telafi butonu yalniz paketi olana (ya da zaten dusulmuse) gosterilir.
                'paket_var'   => ($k->hak_dusuldu || $this->seans->hakVarMi($salonId, (int) $k->user_id, $o->hizmet_id)) ? 1 : 0,
            ])->all(),
            'durumlar' => KatilimciDurumu::sozluk(),
        ];
    }

    /** $terfi=false: mobilin bugunku davranisi, kapasite artisi bekleyeni terfi ettirmez. */
    public function kaydet(int $salonId, ?int $oturumId, array $g, ?int $personelId, ?int $yetkiliId, bool $terfi = true): array
    {
        $this->referans->personelHizmetDogrula($salonId, $g['personel_id'] ?? null, $g['hizmet_id'] ?? null);
        $alan = [
            'personel_id' => $g['personel_id'] ?? null, 'hizmet_id' => $g['hizmet_id'] ?? null,
            'ders_tipi' => ($g['ders_tipi'] ?? null) ?: DersOturumu::VARSAYILAN_AD,
            'tarih' => $g['tarih'], 'saat' => $g['saat'], 'saat_bitis' => $g['saat_bitis'], 'kapasite' => (int) $g['kapasite'],
        ] + array_intersect_key($g, array_flip(['renk', 'not'])); // gonderilmezse mevcut deger korunur

        if ($oturumId) {
            $o = DersOturumu::where('salon_id', $salonId)->findOrFail($oturumId);
            if ($o->iptalMi()) {
                throw new CakismaException('İptal edilmiş ders düzenlenemez.');
            }
            $o->update($alan);
            if ($terfi) {
                $this->katilim->terfiler([(int) $o->id], $yetkiliId); // kapasite artmis olabilir
            }
        } else {
            $o = DersOturumu::create($alan + ['salon_id' => $salonId, 'sube_id' => $salonId, 'aktif' => true, 'olusturan_personel_id' => $personelId]);
        }
        AktiviteLog::yaz($salonId, $yetkiliId, $oturumId ? 'ders_guncelle' : 'ders_ekle', 'ders', (int) $o->id, $o->ad().' '.$o->tarih);

        return ['oturum_id' => (int) $o->id];
    }

    /**
     * Soft iptal: dusulen haklar geri verilir (telafi korunur), katilimcilara bildirim gider.
     * Web kanonik. $mobilKurali: mobilin bugunku davranisi, hak iadesi yok, beklemedekine bildirim yok.
     */
    public function iptal(int $oturumId, ?int $yetkiliId, bool $mobilKurali = false): array
    {
        return DB::transaction(function () use ($oturumId, $yetkiliId, $mobilKurali) {
            $o = DersOturumu::whereKey($oturumId)->lockForUpdate()->first() ?? throw new BulunamadiException('Ders bulunamadı');
            if ($o->iptalMi()) {
                return ['oturum_id' => (int) $o->id, 'bildirilen' => 0];
            }
            $katilimcilar = $o->katilimcilar()->where('durum', '!=', KatilimciDurumu::IPTAL->value)->get();
            if (! $mobilKurali) {
                $katilimcilar->where('hak_dusuldu', true)->each(fn (DersKatilimci $k) => $this->seans->hakGeriVer($k));
            }
            $o->update(['aktif' => false, 'iptal' => true]);
            $alicilar = ($mobilKurali ? $katilimcilar->filter(fn (DersKatilimci $k) => $k->durumu() !== KatilimciDurumu::BEKLEME) : $katilimcilar)
                ->pluck('user_id')->unique()->all();
            $this->bildirim->iptal($o, $alicilar, $yetkiliId);
            AktiviteLog::yaz((int) $o->salon_id, $yetkiliId, 'ders_iptal', 'ders', (int) $o->id, $o->ad().' '.$o->tarih);

            return ['oturum_id' => (int) $o->id, 'bildirilen' => count($alicilar)];
        });
    }

    /** Oturum / katilimci yolundan salon (kapsam denetimi icin); yoksa null. */
    public function salonId(int $oturumId): ?int
    {
        return ($id = DersOturumu::whereKey($oturumId)->value('salon_id')) ? (int) $id : null;
    }

    public function katilimciSalonId(int $katilimciId): ?int
    {
        return ($id = DersKatilimci::whereKey($katilimciId)->value('salon_id')) ? (int) $id : null;
    }

    /** Rol-5 kapsam karari icin: kisitli personelin id'si. */
    public function kisitliPersonel(int $salonId, ?int $yetkiliId, bool $tumPersonel): ?int
    {
        if ($tumPersonel || ! $yetkiliId) {
            return null;
        }
        $p = Personeller::where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)->first(['id', 'role_id']);

        return $p && (int) $p->role_id === 5 ? (int) $p->id : null;
    }

    private function sorgu(int $salonId, string $tarih1, string $tarih2, ?int $personelId)
    {
        return DersOturumu::yayinda()->where('salon_id', $salonId)->whereBetween('tarih', [$tarih1, $tarih2])
            ->when($personelId, fn ($q) => $q->where('personel_id', $personelId))
            ->orderBy('tarih')->orderBy('saat');
    }

    private function sunum(DersOturumu $o): array
    {
        return [
            'id' => (int) $o->id, 'ders_tipi' => $o->ad(), 'hizmet_id' => $o->hizmet_id, 'tarih' => $o->tarih,
            'saat' => $this->saat($o->saat), 'saat_bitis' => $this->saat($o->saat_bitis), 'kapasite' => (int) $o->kapasite,
            'personel_id' => $o->personel_id, 'personel' => $o->personel?->personel_adi ?? '',
        ];
    }

    private function saat(?string $s): string
    {
        return substr((string) $s, 0, 5);
    }
}
