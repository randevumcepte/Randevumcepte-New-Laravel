<?php

namespace App\Domain\Stok;

/**
 * Stok miktari aritmetigi — SAF. Kolonlar decimal(12,3): float karsilastirmalari
 * ESIK toleransiyla, yazilacak degerler 3 haneye yuvarlanarak yapilir.
 */
final class Miktar
{
    public const ESIK = 0.0001;

    public static function yuvarla(float $m): float
    {
        return round($m, 3);
    }

    public static function sifirMi(float $m): bool
    {
        return abs($m) < self::ESIK;
    }

    /** Sayim farki: sayilan − mevcut (yuvarlanmis). */
    public static function fark(float $sayilan, float $mevcut): float
    {
        return self::yuvarla($sayilan - $mevcut);
    }
}
