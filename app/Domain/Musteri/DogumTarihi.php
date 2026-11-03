<?php

namespace App\Domain\Musteri;

/**
 * Dogum tarihi girdisi → 'Y-m-d' | null.
 *
 * Iki istemci bicimi kabul edilir:
 *   - Flutter / v2 JSON : dogum_tarihi = 'Y-m-d'
 *   - Eski web formu    : gun + ay (+ yil opsiyonel; yoksa 1900) — eski
 *                         StoreAdminController::dogum_tarihi_formatla ile ayni kural.
 * Gun/ay yoksa ya da tarih gecersizse null (mevcut deger korunur).
 */
final class DogumTarihi
{
    public const YILSIZ = 1900;

    public static function coz(?string $tarih, mixed $gun = null, mixed $ay = null, mixed $yil = null): ?string
    {
        if ($tarih !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($tarih), $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[0] : null;
        }

        $g = (int) $gun;
        $a = (int) $ay;
        $y = (int) $yil ?: self::YILSIZ;
        if (! $g || ! $a || ! checkdate($a, $g, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $a, $g);
    }

    /** Gosterim icin: yili 1900 olan (yilsiz girilmis) tarihte yil bos doner. */
    public static function parcalar(?string $tarih): array
    {
        if (! $tarih || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $tarih, $m) || $m[1] === '0000') {
            return ['gun' => null, 'ay' => null, 'yil' => null];
        }

        return ['gun' => (int) $m[3], 'ay' => (int) $m[2], 'yil' => (int) $m[1] === self::YILSIZ ? null : (int) $m[1]];
    }
}
