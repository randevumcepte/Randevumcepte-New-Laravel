<?php

namespace App\Services\Stok;

use App\Models\Adisyonlar;
use App\Models\Urunler;
use App\Services\Satis\KalemServisi;
use App\Services\Tahsilat\TahsilatYazServisi;
use App\Support\AktiviteLog;
use App\Support\Idempotency;

/**
 * Hizli satis (kasa) — sepet: [{urun_id, miktar, depo_id?}].
 *
 * Sistemde urun satisi DAIMA bir adisyona baglidir (eskiyle ayni akis):
 *   1) Musteriye adisyon acilir
 *   2) Her kalem satis domaininin KalemServisi'yle yazilir (fiyat = katalog fiyati
 *      × adet; istemcinin gonderdigi fiyat YOK SAYILIR); stoktan 'satis' dusumu de
 *      KalemServisi'nde (urun satan tum akislar icin tek yer)
 *   3) Pesin istenirse tahsilat, tahsilat domaininin TahsilatYazServisi'yle yazilir
 *      (WEB-kanonik dagitim, kalem tahsilatlari, kasa) — kopya tahsilat kodu yok.
 * Tamami tek transaction + idempotency_key (cift tiklamada ikinci adisyon acilmaz).
 */
class HizliSatisServisi
{
    public function __construct(
        private StokKapsam $kapsam,
        private StokHareketServisi $hareket,
        private KalemServisi $kalem,
        private TahsilatYazServisi $tahsilat,
    ) {}

    /**
     * @param  array  $v     HizliSatisIstegi::validated()
     * @param  array  $kim   ['yetkili_id' => int, 'olusturan_personel_id' => ?int]
     */
    public function sat(int $salonId, array $v, array $kim): array
    {
        $sepet = $v['sepet'];
        $musteriId = (int) $v['musteri_id'];
        $personelId = isset($v['personel_id']) ? (int) $v['personel_id'] : null;
        $this->kapsam->musteriOlmali($musteriId, $salonId);
        $this->kapsam->personelOlmali($personelId, $salonId);
        $this->kapsam->aitOlmali('urun', array_column($sepet, 'urun_id'), $salonId);
        $this->kapsam->aitOlmali('depo', array_column($sepet, 'depo_id'), $salonId);
        $yetkiliId = (int) $kim['yetkili_id'];

        $sonuc = Idempotency::calistir('stok_hizli_satis', (string) $v['idempotency_key'], $yetkiliId, $salonId,
            function () use ($salonId, $v, $sepet, $musteriId, $personelId, $kim, $yetkiliId) {
                $tarih = $v['satis_tarihi'] ?? now()->toDateString();
                $adisyon = Adisyonlar::ac($salonId, $musteriId, $yetkiliId, $tarih);
                $batch = $this->hareket->yeniBatch();
                $kalemIdleri = [];
                $toplam = 0.0;

                foreach ($sepet as $k) {
                    $urun = Urunler::findOrFail($k['urun_id']);
                    $adet = (int) $k['miktar'];
                    // Kalem + stok 'satis' hareketi TEK yazıcıda (KalemServisi::ekle).
                    $kalem = $this->kalem->ekle((int) $adisyon->id, 'urun', (int) $urun->id, [
                        'adet' => $adet, 'personel_id' => $personelId, 'depo_id' => $k['depo_id'] ?? null,
                        'batch_uuid' => $batch, 'aciklama' => $v['aciklama'] ?? 'Hızlı satış', 'kullanici_id' => $yetkiliId,
                    ]);
                    $kalemIdleri[] = $kalem['kalem_id'];
                    $toplam += round((float) $urun->fiyat * $adet, 2);
                }

                $tahsilatId = null;
                if (! empty($v['tahsilat_yap']) && $toplam > 0) {
                    $t = $this->tahsilat->yaz([
                        'salon_id'         => $salonId,
                        'adisyon_id'       => (int) $adisyon->id,
                        'kalem_urun_id'    => $kalemIdleri,
                        'tutar'            => $toplam,
                        'odeme_yontemi_id' => (int) $v['odeme_yontemi_id'],
                        'odeme_tarihi'     => $tarih,
                        'notlar'           => 'Hızlı satış',
                        'idempotency_key'  => $v['idempotency_key'].':tahsilat',
                    ], ['yetkili_id' => $yetkiliId, 'salon_id' => $salonId, 'olusturan_id' => $kim['olusturan_personel_id'] ?? null]);
                    $tahsilatId = $t['tahsilat_id'];
                }

                return [
                    'adisyon_id'   => (int) $adisyon->id,
                    'tahsilat_id'  => $tahsilatId,
                    'batch_uuid'   => $batch,
                    'kalem_sayisi' => count($kalemIdleri),
                    'toplam_tutar' => round($toplam, 2),
                ];
            });

        if (! $sonuc['replay']) {
            AktiviteLog::yaz($salonId, $yetkiliId, 'stok_hizli_satis', 'adisyon', $sonuc['adisyon_id'],
                $sonuc['kalem_sayisi'].' kalem — '.number_format($sonuc['toplam_tutar'], 2, ',', '.').' ₺',
                $sonuc['tahsilat_id'] ? 'Hızlı satış + peşin tahsilat' : 'Hızlı satış (ödeme bekliyor)');
        }

        return $sonuc;
    }
}
