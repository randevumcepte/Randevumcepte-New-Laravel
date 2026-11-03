<?php

namespace App\Domain\Seans;

/**
 * Cihaz (lazer parametresi) takibi yalniz lazer epilasyon icin: paket ya da hizmet
 * adinda "lazer"/"laser" geciyorsa. Eski web (isimdeLazerVar) + mobil (apiSeansLazerMi)
 * ile ayni kural, tek yerde.
 */
final class LazerTespiti
{
    public static function adlardanBiri(?string ...$adlar): bool
    {
        foreach ($adlar as $ad) {
            $ad = (string) $ad;
            if ($ad !== '' && (stripos($ad, 'lazer') !== false || stripos($ad, 'laser') !== false)) {
                return true;
            }
        }

        return false;
    }
}
