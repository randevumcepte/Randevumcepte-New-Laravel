<?php

namespace App\Services\SistemYonetim\Izleme;

use Illuminate\Support\Carbon;

/**
 * Paylasilan tablolardaki tarihler Turkiye yerel saatiyle yazilir (eski monolit + MySQL NOW()); uygulama saat dilimi ise
 * UTC. Pencere sinirlari, gosterim ve "x once" hesabi bu yuzden acik bolgeyle yapilir: aksi halde gun sinirlari kayar,
 * duran watchdog 3 saat boyunca canli gorunur.
 */
final class IzlemeZamani
{
    private const BOLGE = 'Europe/Istanbul';

    public static function simdi(): Carbon
    {
        return Carbon::now(self::BOLGE);
    }

    /** Bugunden $gun once, gun basi — SQL karsilastirmasi icin. */
    public static function gunBasi(int $gun = 0): string
    {
        return self::simdi()->subDays($gun)->format('Y-m-d 00:00:00');
    }

    public static function oku(?string $tarih): ?Carbon
    {
        return $tarih ? Carbon::parse($tarih, self::BOLGE)->locale('tr') : null;
    }

    public static function bicim(?string $tarih, string $bicim = 'd.m.Y H:i:s'): ?string
    {
        return self::oku($tarih)?->format($bicim);
    }

    public static function once(?string $tarih): ?string
    {
        return self::oku($tarih)?->diffForHumans();
    }
}
