<?php

namespace App\Domain\Randevu;

/**
 * "Randevudan tahsilata" — randevunun hangi kalemleri adisyona eklenecek? SAF kural
 * (DB yok, unit-test'li). Eski randevutahsilet + tahsilataGitRandevudan ile ayni eleme:
 *
 * Her zaman atlanir: yardimci personel satiri, telafi randevusu kalemi (ucretsiz),
 * hizmeti olmayan satir, paketten dusen hizmet (APS), bu randevu icin adisyonda
 * zaten olan hizmet (cift tiklama / tekrar giris idempotent).
 *
 * Randevunun adisyonu zaten varken (ekstra modu) ayrica: ucretsiz kalem ile o
 * adisyonda/paket iceriginde olan hizmet de atlanir — geriye gercek ekstra kalir.
 */
final class TahsilatKalemSecimi
{
    /**
     * @param  list<object>  $kalemler  randevu_hizmetler satirlari
     * @param  list<int>  $haricHizmetler  paketten dusen + bu randevu icin adisyonda olan hizmetler
     * @param  list<int>  $mevcutAdisyonHizmetleri  ekstra modu: adisyon + paket icerigi hizmetleri
     * @return list<object>
     */
    public static function eklenecekler(array $kalemler, array $haricHizmetler, bool $ekstraModu = false, array $mevcutAdisyonHizmetleri = []): array
    {
        $eklenecek = [];
        foreach ($kalemler as $k) {
            $hizmetId = (int) ($k->hizmet_id ?? 0);
            if ($hizmetId <= 0 || ! empty($k->yardimci_personel) || ! empty($k->telafi_kaynak_rh_id)) {
                continue;
            }
            if (in_array($hizmetId, $haricHizmetler, true)) {
                continue;
            }
            if ($ekstraModu && ((float) ($k->fiyat ?? 0) <= 0 || in_array($hizmetId, $mevcutAdisyonHizmetleri, true))) {
                continue;
            }
            $eklenecek[] = $k;
        }

        return $eklenecek;
    }
}
