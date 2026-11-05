<?php

namespace App\Services\Paket;

use App\Domain\Paket\PaketGecerlilik;
use App\Domain\Paket\PaketTutari;
use App\Exceptions\BulunamadiException;
use App\Models\PaketHizmetler;
use App\Models\Paketler;
use App\Services\Yetki\SalonSahipligi;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Paket tanimi YAZMA — eski web paket_ekle_guncelle / paket_sil ve mobil v1
 * paket_ekle_guncelle'nin TEK karsiligi (iki kanal artik ayni kurali yazar).
 *
 *   - Hizmetler bu salonun sundugu hizmetler olmali (eski kod denetlemiyordu).
 *   - paketler.fiyat / miktar = PaketTutari::etkin (hizmet toplami > 0 ise o, degilse
 *     paket duzeyi) → eski panel, katalog ve v2 ayni degeri okur.
 *   - Guncellemede yalniz GONDERILEN alanlar degisir; 'hizmetler' gonderilirse
 *     paket_hizmetler tamamen yenilenir (eskiyle ayni: sil + yeniden yaz).
 *   - Silme FIZIKSEL DEGIL: aktif=0 (gecmis adisyon_paketler kirilmasin).
 */
class PaketYazServisi
{
    public function __construct(
        private SalonSahipligi $sahiplik,
        private PaketSorgusu $sorgu,
    ) {}

    /** Tur + gecerlilik. Sinirsizda miktar = teknik tavan (PaketGecerlilik::ustSinir). */
    private function gecerlilikYaz(Paketler $p, array $v): void
    {
        if (! PaketKullanimi::kolonVar()) {
            return;
        }
        foreach (['sinirsiz', 'gecerlilik_sayi', 'gecerlilik_birim', 'gunluk_limit'] as $alan) {
            if (array_key_exists($alan, $v)) {
                $p->{$alan} = $alan === 'sinirsiz' ? (int) $v[$alan] : $v[$alan];
            }
        }
        if (! $p->gecerlilik_sayi) {
            $p->gecerlilik_birim = null;
        }
        $p->gunluk_limit = $p->sinirsiz ? max(1, (int) $p->gunluk_limit) : null;
        if ($p->sinirsiz) {
            $p->miktar = PaketGecerlilik::ustSinir($p->gecerlilik_sayi, $p->gecerlilik_birim, $p->gunluk_limit);
        }
    }

    /**
     * @param  array  $v  PaketKaydetIstegi::validated() — paket_adi, hizmetler[{hizmet_id, seans?, fiyat?}], miktar?, fiyat?, sure?
     */
    public function kaydet(int $salonId, ?int $id, array $v, int $yetkiliId): array
    {
        $yeni = $id === null;
        if (isset($v['hizmetler'])) {
            $this->sahiplik->hizmetOlmali(array_column($v['hizmetler'], 'hizmet_id'), $salonId);
        }

        $paket = DB::transaction(function () use ($salonId, $id, $yeni, $v) {
            $p = $yeni ? new Paketler(['salon_id' => $salonId]) : $this->aktifKilitli($id);
            $hizmetler = $v['hizmetler'] ?? $p->hizmetler()->get(['seans', 'fiyat'])->toArray();
            $etkin = PaketTutari::etkin(
                array_key_exists('fiyat', $v) ? $v['fiyat'] : $p->fiyat,
                array_key_exists('miktar', $v) ? $v['miktar'] : $p->miktar,
                $hizmetler,
            );

            if (array_key_exists('paket_adi', $v)) {
                $p->paket_adi = $v['paket_adi'];
            }
            if (array_key_exists('sure', $v)) {
                $p->sure = $v['sure'];
            }
            $p->aktif = 1;
            $p->fiyat = $etkin['fiyat'];
            $p->miktar = $etkin['seans'];
            $this->gecerlilikYaz($p, $v);
            $p->save();

            if (isset($v['hizmetler'])) {
                PaketHizmetler::where('paket_id', $p->id)->delete();
                foreach ($v['hizmetler'] as $h) {
                    PaketHizmetler::create([
                        'paket_id'  => $p->id,
                        'hizmet_id' => (int) $h['hizmet_id'],
                        'seans'     => $p->sinirsiz ? null : ($h['seans'] ?? null),
                        'fiyat'     => $h['fiyat'] ?? null,
                    ]);
                }
            }

            if ($p->sinirsiz) {
                PaketHizmetler::where('paket_id', $p->id)->update(['seans' => null]); // toplu randevu/etkin seans hesabina girmesin
            }

            return $p;
        });

        $sonuc = $this->sorgu->getir((int) $paket->id);
        AktiviteLog::yaz($salonId, $yetkiliId, $yeni ? 'paket_ekle' : 'paket_guncelle', 'paket', $sonuc['id'], $sonuc['paket_adi'],
            $yeni ? 'Yeni paket eklendi' : 'Paket güncellendi',
            ['fiyat' => $sonuc['fiyat'], 'seans' => $sonuc['miktar'], 'sure' => $sonuc['sure']]);

        return $sonuc;
    }

    /** Pasif yap (idempotent: zaten pasifse yeniden log yazilmaz). */
    public function sil(int $id, int $yetkiliId): array
    {
        $p = Paketler::find($id) ?? throw new BulunamadiException('Paket bulunamadı');
        if ($p->aktif) {
            $p->aktif = 0;
            $p->save();
            AktiviteLog::yaz((int) $p->salon_id, $yetkiliId, 'paket_sil', 'paket', (int) $p->id,
                $p->paket_adi ?: 'Paket #'.$p->id, 'Paket katalogdan kaldırıldı (pasif)');
        }

        return ['id' => (int) $p->id, 'aktif' => 0];
    }

    private function aktifKilitli(int $id): Paketler
    {
        return Paketler::whereKey($id)->where('aktif', 1)->lockForUpdate()->first()
            ?? throw new BulunamadiException('Paket bulunamadı veya silinmiş');
    }
}
