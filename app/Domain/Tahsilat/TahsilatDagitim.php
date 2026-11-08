<?php

namespace App\Domain\Tahsilat;

/**
 * Tahsilat tutarının kalemlere DAĞITIMI — SAF (kuruş, DB yok, test edilebilir). Web ve mobil AYNI yeri çağırır.
 *
 * Eski WEB `StoreAdminController` kanonik mantığından portlanmıştır (b171f97b kuruş reconcile, fbebbfd7 tek havuz + kalan-cap):
 *   - reconcile()   : taban paylara oranlı, kuruş artığı en büyük kaleme (tahsilatPaylariReconcile, StoreAdmin 257-279).
 *                     Toplam pay HEDEFe birebir eşittir. Kalan sınırı yoktur (taksit ön ödemesi).
 *   - oransal()     : ağırlığa oranlı, her pay kendi kapasitesiyle (kalan borç) sınırlı; yuvarlama ve sınır artığı kapasitesi
 *                     kalan kalemlere verilir. Toplam = min(hedef, toplam kapasite) — kuruş-kesin.
 *   - taksitHavuz() : ödeme dağıtımı — tek havuz + kalan-cap (StoreAdmin 13979-14019): ağırlık = kapasite = kalan.
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
     * Ödemenin kalemlere dağıtımı — tek havuz + kalan-cap, kuruş-kesin.
     *
     * @param array<int,int> $kalanlarKurus    Her kalemin KALAN borcu (fiyat−indirim−önceki), kuruş
     * @param int            $kismiOdemeKurus  Dağıtılacak ödeme, kuruş
     * @return array<int,int> Kalem indeksi => ödeme (kuruş). Yalnızca payı>0 olanlar; toplamı min(ödeme, toplam kalan).
     */
    public static function taksitHavuz(array $kalanlarKurus, int $kismiOdemeKurus): array
    {
        return self::oransal($kalanlarKurus, $kalanlarKurus, $kismiOdemeKurus);
    }

    /**
     * Ağırlığa oranlı, kapasiteyle sınırlı dağıtım.
     *
     * 1) dağıtılacak = min(hedef, Σ kapasite); 2) pay = round(ağırlık/Σağırlık × dağıtılacak), kapasiteyi AŞAMAZ;
     * 3) artık > 0 ise kapasitesi kalan kalemlere, en büyük boş kapasite önce, kuruş kuruş (büyük artıkta eşit dilimle) verilir;
     *    artık < 0 ise (yukarı yuvarlama) en büyük paydan kuruş kuruş geri alınır. Sonuç toplamı tam dağıtılacak tutardır.
     *
     * @param array<int,int> $agirliklarKurus   Oran tabanı (ör. fiyat veya kalan), kuruş
     * @param array<int,int> $kapasitelerKurus  Her kalemin alabileceği en çok (kalan borç), kuruş
     * @return array<int,int> Kalem indeksi => pay (kuruş), yalnız pay>0 olanlar, giriş sırasıyla.
     */
    public static function oransal(array $agirliklarKurus, array $kapasitelerKurus, int $hedefKurus): array
    {
        $kapasite = [];
        foreach ($agirliklarKurus as $i => $_) {
            $kapasite[$i] = max(0, (int) ($kapasitelerKurus[$i] ?? 0));
        }
        $dagitilacak = min(max(0, $hedefKurus), array_sum($kapasite));
        if ($dagitilacak <= 0) {
            return [];
        }

        $agirlik = array_map(fn ($a) => max(0, (int) $a), $agirliklarKurus);
        $toplamAgirlik = array_sum($agirlik);

        $pay = [];
        foreach ($kapasite as $i => $k) {
            $p = $toplamAgirlik > 0 ? (int) round($agirlik[$i] / $toplamAgirlik * $dagitilacak) : 0;
            $pay[$i] = min(max(0, $p), $k);
        }

        $artik = $dagitilacak - array_sum($pay);
        while ($artik > 0) {
            $adaylar = array_keys(array_filter($kapasite, fn ($k, $i) => $k > $pay[$i], ARRAY_FILTER_USE_BOTH));
            usort($adaylar, fn ($a, $b) => [$kapasite[$b] - $pay[$b], $a] <=> [$kapasite[$a] - $pay[$a], $b]);
            $dilim = max(1, intdiv($artik, count($adaylar)));
            foreach ($adaylar as $i) {
                $ver = min($dilim, $kapasite[$i] - $pay[$i], $artik);
                $pay[$i] += $ver;
                $artik -= $ver;
                if ($artik === 0) {
                    break;
                }
            }
        }
        while ($artik < 0) {
            $adaylar = array_keys(array_filter($pay, fn ($p) => $p > 0));
            usort($adaylar, fn ($a, $b) => [$pay[$b], $a] <=> [$pay[$a], $b]);
            foreach ($adaylar as $i) {
                $pay[$i]--;
                if (++$artik === 0) {
                    break;
                }
            }
        }

        return array_filter($pay, fn ($p) => $p > 0);
    }
}
