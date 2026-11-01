<?php

namespace App\Support;

/**
 * Randevu v2 yazma kapisi (kill-switch) — TEK KAYNAK. Genel acik ya da salon
 * pilot listesinde ise yazmaya izin verir (config/randevu.php).
 */
class RandevuYazma
{
    public static function acik(int $salonId): bool
    {
        return (bool) config('randevu.yazma_aktif')
            || in_array($salonId, (array) config('randevu.yazma_salonlari', []), true);
    }
}
