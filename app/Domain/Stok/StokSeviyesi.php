<?php

namespace App\Domain\Stok;

/**
 * Stok seviyesi kurali — SAF (DB yok, unit-test edilir). Web rozeti, Flutter rozeti,
 * ozet kartlari ve "dusuk stok" listesi AYNI kurali kullanir:
 *
 *   stok <= 0                         → tukendi (kirmizi)
 *   kritik > 0 ve stok <= kritik      → kritik  (kirmizi)
 *   dusuk  > 0 ve stok <= dusuk       → dusuk   (sari)
 *   aksi                              → normal  (yesil)
 *
 * Miktarlar decimal(12,3); karsilastirmalar Miktar::ESIK toleransiyla yapilir.
 */
final class StokSeviyesi
{
    public const TUKENDI = 'tukendi';
    public const KRITIK = 'kritik';
    public const DUSUK = 'dusuk';
    public const NORMAL = 'normal';

    /** UI rozet rengi (eski ekranla ayni anlam). */
    private const RENK = [
        self::TUKENDI => 'kirmizi',
        self::KRITIK  => 'kirmizi',
        self::DUSUK   => 'sari',
        self::NORMAL  => 'yesil',
    ];

    public static function hesapla(float $stok, ?float $dusukSinir, ?float $kritikSinir): string
    {
        if ($stok <= Miktar::ESIK) {
            return self::TUKENDI;
        }
        if ($kritikSinir !== null && $kritikSinir > 0 && $stok <= $kritikSinir + Miktar::ESIK) {
            return self::KRITIK;
        }
        if ($dusukSinir !== null && $dusukSinir > 0 && $stok <= $dusukSinir + Miktar::ESIK) {
            return self::DUSUK;
        }

        return self::NORMAL;
    }

    public static function renk(string $seviye): string
    {
        return self::RENK[$seviye] ?? 'yesil';
    }

    /** Uyari gerektiren (ozet "Düşük Stok" + dusuk-stok listesi) seviye mi? Tukenen ayri sayilir. */
    public static function uyariMi(string $seviye): bool
    {
        return $seviye === self::KRITIK || $seviye === self::DUSUK;
    }
}
