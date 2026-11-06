<?php

namespace App\Domain\SistemYonetim;

/** Paket fiyatlari tum vergiler dahil girilir: matrah ve KDV toplamdan cikarilir. */
final class KdvHesabi
{
    public const ORAN = 0.20;

    /** @return array{matrah:float,kdv:float} */
    public static function dahilTutardan(float $tutar): array
    {
        $matrah = round($tutar / (1 + self::ORAN), 2);

        return ['matrah' => $matrah, 'kdv' => round($tutar - $matrah, 2)];
    }

    public static function yuzde(): int
    {
        return (int) round(self::ORAN * 100);
    }
}
