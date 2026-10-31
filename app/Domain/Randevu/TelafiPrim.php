<?php

namespace App\Domain\Randevu;

/**
 * Telafi randevusu PRIM GERI DEVRI — saf hesap (DB yok, unit-test edilir).
 * Eski App\Services\TelafiRandevuServisi::primTransfer kuraliyla BIREBIR.
 *
 * Kural (personel degistiyse):
 *   - Satis kalemi / paket seansi HER DURUMDA yeni personele devredilir
 *     (hak edis hesabi primi otomatik yeni personele yansitir).
 *   - Orijinal personelin o DONEM primi ODENMISSE (2b) ve bu hizmetten prim
 *     KAZANDIYSA: orijinal personele −tutar 'telafi_devir' hareketi yazilir.
 *   - Musteri odememisse ya da yuzde 0 ise prim kazanilmamistir → geri devir YOK.
 *
 * Donus: geri devredilecek POZITIF tutar (TL, 2 hane); 0 = hareket yazilmaz.
 */
final class TelafiPrim
{
    /** Tek seferlik satis kalemi: kazanilan prim = musterinin odedigi × yuzde. */
    public static function hizmetGeriDevir(float $musterininOdedigi, float $yuzde, bool $donemPrimiOdenmis): float
    {
        $tutar = round($musterininOdedigi * $yuzde / 100, 2);

        return ($donemPrimiOdenmis && $tutar > 0) ? $tutar : 0.0;
    }

    /** Paket: seans basi odeme = paket tahsilati / seans sayisi; prim = seans basi × yuzde. */
    public static function paketGeriDevir(float $paketTahsilati, int $seansSayisi, float $yuzde, bool $donemPrimiOdenmis): float
    {
        if ($paketTahsilati <= 0 || $seansSayisi <= 0) {
            return 0.0;
        }
        $tutar = round($paketTahsilati / $seansSayisi * $yuzde / 100, 2);

        return ($donemPrimiOdenmis && $tutar > 0) ? $tutar : 0.0;
    }
}
