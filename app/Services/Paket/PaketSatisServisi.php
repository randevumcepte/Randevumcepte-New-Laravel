<?php

namespace App\Services\Paket;

use App\Exceptions\YetkisizErisimException;
use App\Models\Adisyonlar;
use App\Services\Satis\KalemServisi;
use App\Services\Yetki\SalonSahipligi;
use App\Support\AktiviteLog;
use App\Support\Idempotency;

/**
 * Paket Yonetimi "Satis Yap" — secilen paketler musteriye tek adisyonla satilir
 * (eski pakettahsilatagit). Tahsilat BURADA ALINMAZ: istemci donen adisyon_id ile
 * tahsilat ekranina gider (/v2/tahsilat/{adisyon}); studyo "Odeme Alindi" akisi
 * mevcut PATCH /adisyon/{id}/odeme ucunu kullanir — kopya tahsilat kodu yok.
 *
 * Eskiden farklar (guvenlik / tutarlilik):
 *   - Musteri, satici personel ve paketler istekteki salona ait olmali.
 *   - Kalem fiyati katalogdan (PaketTutari); farkli fiyat yalniz 'satis.indirim_uygula'
 *     yetkisiyle kabul edilir (eskide herkes fiyat degistirebiliyordu).
 *   - idempotency_key: cift tiklamada ikinci adisyon acilmaz.
 */
class PaketSatisServisi
{
    public function __construct(
        private SalonSahipligi $sahiplik,
        private PaketSorgusu $sorgu,
        private KalemServisi $kalem,
    ) {}

    /**
     * @param  array  $v    PaketSatisIstegi::validated()
     * @param  array{yetkili_id:int, personel_id:?int, fiyat_degistirebilir:bool}  $kim
     */
    public function sat(int $salonId, array $v, array $kim): array
    {
        $musteriId = (int) $v['musteri_id'];
        $saticiId = isset($v['personel_id']) ? (int) $v['personel_id'] : $kim['personel_id'];
        $this->sahiplik->musteriOlmali($musteriId, $salonId);
        $this->sahiplik->personelOlmali($saticiId, $salonId);

        // Katalog fiyatlari once (salon + aktiflik denetimi), fiyat yetkisi yazmadan once.
        $kalemler = array_map(function (array $k) use ($salonId, $kim) {
            $katalog = $this->sorgu->satisTutari((int) $k['paket_id'], $salonId);
            $fiyat = isset($k['fiyat']) ? round((float) $k['fiyat'], 2) : $katalog['fiyat'];
            if ($fiyat !== $katalog['fiyat'] && ! $kim['fiyat_degistirebilir']) {
                throw new YetkisizErisimException('Paket fiyatını değiştirme yetkiniz yok ('.$katalog['paket_adi'].')');
            }

            return ['paket_id' => (int) $k['paket_id'], 'fiyat' => $fiyat, 'seans' => isset($k['seans']) ? (int) $k['seans'] : $katalog['seans']];
        }, $v['kalemler']);

        $yetkiliId = (int) $kim['yetkili_id'];
        $sonuc = Idempotency::calistir('paket_satis', (string) $v['idempotency_key'], $yetkiliId, $salonId,
            function () use ($salonId, $v, $musteriId, $saticiId, $kalemler, $yetkiliId) {
                $adisyon = Adisyonlar::ac($salonId, $musteriId, $yetkiliId, $v['tarih'] ?? null);
                foreach ($kalemler as $k) {
                    $this->kalem->ekle((int) $adisyon->id, 'paket', $k['paket_id'], ['personel_id' => $saticiId, 'fiyat' => $k['fiyat'], 'seans' => $k['seans']]);
                }

                return [
                    'adisyon_id'   => (int) $adisyon->id,
                    'musteri_id'   => $musteriId,
                    'kalem_sayisi' => count($kalemler),
                    'toplam_tutar' => round(array_sum(array_column($kalemler, 'fiyat')), 2),
                ];
            });

        if (! $sonuc['replay']) {
            AktiviteLog::yaz($salonId, $yetkiliId, 'paket_satis', 'adisyon', $sonuc['adisyon_id'],
                $sonuc['kalem_sayisi'].' paket — '.number_format($sonuc['toplam_tutar'], 2, ',', '.').' ₺', 'Paket satışı (ödeme bekliyor)');
        }

        return $sonuc;
    }
}
