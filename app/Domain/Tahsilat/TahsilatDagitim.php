<?php

namespace App\Domain\Tahsilat;

/**
 * Tahsilat tutarının kalemlere DAĞITIMI — SAF (kuruş, DB yok, test edilebilir).
 *
 * Eski WEB `StoreAdminController` kanonik mantığından BİREBİR portlanmıştır
 * (fbebbfd7 düzeltmesi WEB'dedir; API sürümü bug'lı olduğu için baz ALINMADI):
 *   - reconcile()   : taksitsiz/senetsiz kalem payları — oran + kuruş artığı en
 *                     büyük kaleme (tahsilatPaylariReconcile, StoreAdmin 257-279).
 *                     Toplam pay HEDEFe birebir eşittir.
 *   - taksitHavuz() : taksit ödemesi — tek havuz + kalan-cap (StoreAdmin 13979-14019).
 *                     Her pay kendi kalanını AŞAMAZ; toplam dağıtılacağı aşamaz.
 *
 * Tüm aritmetik TAM SAYI KURUŞ. Hiçbir şey YAZMAZ.
 */
class TahsilatDagitim
{
    /**
     * Taksitsiz/senetsiz kalem dağıtımı — toplam = $hedefKurus (kuruş-kesin).
     *
     * @param array<int,int> $rawlarKurus  Her kalemin "taban payı" (ör. girilen tutar), kuruş
     * @param int            $hedefKurus   Dağıtılacak toplam (adisyon ödemesi), kuruş
     * @return array<int,int> Kalem indeksi => pay (kuruş). Toplamı tam $hedefKurus.
     */
    public static function reconcile(array $rawlarKurus, int $hedefKurus): array
    {
        if (empty($rawlarKurus)) {
            return [];
        }

        $taban = array_sum($rawlarKurus);
        $paylar = [];
        $toplamAtanan = 0;
        $enBuyukIdx = array_key_first($rawlarKurus);
        $enBuyukRaw = PHP_INT_MIN;

        foreach ($rawlarKurus as $i => $raw) {
            $pay = $taban > 0 ? (int) round($raw / $taban * $hedefKurus) : 0;
            $paylar[$i] = $pay;
            $toplamAtanan += $pay;

            if ($raw > $enBuyukRaw) {
                $enBuyukRaw = $raw;
                $enBuyukIdx = $i;
            }
        }

        // Kuruş artığını en büyük raw'lı kaleme bindir → toplam birebir hedef.
        $artik = $hedefKurus - $toplamAtanan;
        if ($artik !== 0) {
            $paylar[$enBuyukIdx] += $artik;
        }

        return $paylar;
    }

    /**
     * Taksit ödemesinin kalemlere dağıtımı — tek havuz + kalan-cap.
     *
     * @param array<int,int> $kalanlarKurus  Her kalemin KALAN borcu (fiyat−indirim−önceki), kuruş
     * @param int            $kismiOdemeKurus  Bu vadede dağıtılacak ödeme, kuruş
     * @return array<int,int> Kalem indeksi => ödeme (kuruş). Yalnızca kalanı>0 ve payı>0 olanlar.
     */
    public static function taksitHavuz(array $kalanlarKurus, int $kismiOdemeKurus): array
    {
        $havuz = [];
        $toplamKalanBorc = 0;
        foreach ($kalanlarKurus as $i => $kalan) {
            if ($kalan > 0) {
                $havuz[$i] = $kalan;
                $toplamKalanBorc += $kalan;
            }
        }

        $odemeler = [];
        if ($toplamKalanBorc <= 0) {
            return $odemeler;
        }

        $dagitilacak = min($kismiOdemeKurus, $toplamKalanBorc);

        foreach ($havuz as $i => $kalan) {
            $odeme = (int) round($kalan / $toplamKalanBorc * $dagitilacak);
            if ($odeme > $kalan) {
                $odeme = $kalan; // kendi kalanını AŞAMAZ
            }
            if ($odeme <= 0) {
                continue;
            }
            $odemeler[$i] = $odeme;
        }

        return $odemeler;
    }
}
