<?php

namespace App\Domain\Musteri;

/**
 * Musteri uygulamasi ana sayfasindaki kategori (SAF). Web ile ayni kural: odeme sayisina gore
 * 0 pasif, 1-2 aktif, 3 ve uzeri sadik.
 */
final class MusteriKategorisi
{
    private const SADIK_ESIK = 3;

    /** @return array{0:string,1:string} kod + ekranda gorunen ad */
    public static function coz(int $odemeSayisi): array
    {
        if ($odemeSayisi >= self::SADIK_ESIK) {
            return ['sadik', 'Sadık Müşteri'];
        }

        return $odemeSayisi >= 1 ? ['aktif', 'Aktif Müşteri'] : ['pasif', 'Pasif Müşteri'];
    }
}
