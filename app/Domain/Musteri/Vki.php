<?php

namespace App\Domain\Musteri;

/** Vucut kitle indeksi — eski App\MusteriOlcum::vkiHesapla / vkiSinif ile BIREBIR. */
final class Vki
{
    /** boy cm, kilo kg → VKI (2 hane) | null */
    public static function hesapla(mixed $boy, mixed $kilo): ?float
    {
        $boy = (float) $boy;
        $kilo = (float) $kilo;
        if ($boy <= 0 || $kilo <= 0) {
            return null;
        }
        $m = $boy / 100.0;

        return round($kilo / ($m * $m), 2);
    }

    public static function sinif(?float $vki): string
    {
        $v = (float) $vki;

        return match (true) {
            $v <= 0    => '',
            $v < 18.5  => 'Zayıf',
            $v < 25    => 'Normal',
            $v < 30    => 'Fazla Kilolu',
            default    => 'Obez',
        };
    }
}
