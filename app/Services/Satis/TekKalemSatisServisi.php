<?php

namespace App\Services\Satis;

use App\Exceptions\GecersizIslemException;
use App\Exceptions\YetkisizErisimException;
use App\Models\Adisyonlar;
use App\Services\Paket\PaketSatisServisi;
use App\Services\Stok\HizliSatisServisi;
use App\Support\Idempotency;

/**
 * TEK KALEMLİ SATIŞ AÇ — "bir paket / ürün / hizmet sattım" diyen akışların ortak yazıcısı
 * (Ön Görüşme "Satış Yapıldı" ve Çağrı Merkezi "Telefonda Satış"). Kopya adisyon/kalem/stok
 * kodu yok; türüne göre MEVCUT v2 satış yazıcısına gider:
 *   paket  → PaketSatisServisi (katalog fiyatı; farklı fiyat 'satis.indirim_uygula' ister)
 *   ürün   → HizliSatisServisi (katalog fiyatı × adet + stok 'satis' hareketi; istemci fiyatı yok sayılır)
 *   hizmet → adisyon + KalemServisi hizmet kalemi (seans 2+ → seanslı; fiyat kuralı paketle aynı)
 * Tahsilat BURADA ALINMAZ — istemci dönen adisyon_id ile tahsilat ekranına gider (stüdyo: ödeme
 * işareti). Çift tıklama koruması: çağıranın verdiği idempotency_key.
 */
class TekKalemSatisServisi
{
    public const TURLER = ['paket', 'urun', 'hizmet'];

    public function __construct(
        private PaketSatisServisi $paketSatis,
        private HizliSatisServisi $urunSatis,
        private KalemServisi $kalem,
    ) {}

    /**
     * @param array{musteri_id:int, personel_id:?int, idempotency_key:string, tarih:?string,
     *              fiyat?:?float, seans?:?int, adet?:?int, aciklama?:?string} $g
     * @param array{yetkili_id:int, personel_id:?int, fiyat_degistirebilir:bool} $kim
     * @return array{adisyon_id:int, toplam_tutar:float, replay:bool}
     */
    public function sat(int $salonId, string $tur, int $katalogId, array $g, array $kim): array
    {
        $tarih = $g['tarih'] ?? now()->toDateString();
        $ortak = ['musteri_id' => (int) $g['musteri_id'], 'personel_id' => $g['personel_id'] ?? null, 'idempotency_key' => (string) $g['idempotency_key']];

        $s = match ($tur) {
            'paket' => $this->paketSatis->sat($salonId, $ortak + ['tarih' => $tarih, 'kalemler' => [
                array_filter(['paket_id' => $katalogId, 'seans' => $g['seans'] ?? null, 'fiyat' => $g['fiyat'] ?? null], fn ($x) => $x !== null),
            ]], $kim),
            'urun' => $this->urunSatis->sat($salonId, $ortak + ['satis_tarihi' => $tarih, 'aciklama' => $g['aciklama'] ?? 'Satış',
                'sepet' => [['urun_id' => $katalogId, 'miktar' => max(1, (int) ($g['adet'] ?? 1))]]],
                ['yetkili_id' => (int) $kim['yetkili_id'], 'olusturan_personel_id' => $kim['personel_id'] ?? null]),
            'hizmet' => $this->hizmetSat($salonId, $katalogId, $ortak + ['tarih' => $tarih, 'fiyat' => $g['fiyat'] ?? null, 'seans' => $g['seans'] ?? null], $kim),
            default => throw new GecersizIslemException('Geçersiz satış türü (paket / urun / hizmet)'),
        };

        return ['adisyon_id' => (int) $s['adisyon_id'], 'toplam_tutar' => (float) ($s['toplam_tutar'] ?? 0), 'replay' => (bool) ($s['replay'] ?? false)];
    }

    /** Hizmet satışı: adisyon + tek hizmet kalemi. Katalogdan farklı fiyat yalnız 'satis.indirim_uygula' ile. */
    private function hizmetSat(int $salonId, int $hizmetId, array $g, array $kim): array
    {
        $katalog = $this->kalem->katalogFiyati('hizmet', $hizmetId, $salonId);
        $fiyat = isset($g['fiyat']) ? round((float) $g['fiyat'], 2) : $katalog;
        if ($fiyat !== $katalog && ! $kim['fiyat_degistirebilir']) {
            throw new YetkisizErisimException('Hizmet fiyatını değiştirme yetkiniz yok');
        }
        $yetkiliId = (int) $kim['yetkili_id'];

        return Idempotency::calistir('hizmet_satis', $g['idempotency_key'], $yetkiliId, $salonId, function () use ($salonId, $hizmetId, $g, $fiyat, $yetkiliId) {
            $adisyon = Adisyonlar::ac($salonId, (int) $g['musteri_id'], $yetkiliId, $g['tarih']);
            $this->kalem->ekle((int) $adisyon->id, 'hizmet', $hizmetId, [
                'personel_id' => $g['personel_id'], 'fiyat' => $fiyat, 'seans' => $g['seans'] ?? null, 'tarih' => $g['tarih'], 'kullanici_id' => $yetkiliId,
            ]);

            return ['adisyon_id' => (int) $adisyon->id, 'toplam_tutar' => $fiyat];
        });
    }
}
