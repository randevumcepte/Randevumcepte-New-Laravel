<?php

namespace App\Domain\Kampanya;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Saat boşluğu kampanyası: `kampanyalar` satırındaki `[gap:KEY]` etiketi, indirim ve saat metnini
 * okur (saf, DB'siz). Eski aktifGapKampanyalari ile aynı kural; etiketsiz satır null döner.
 */
final class SaatBosluguKampanyasi
{
    private const BOSLUKLAR = [
        'morning'   => ['ad' => 'Sabah',         'bas' => 9,  'bit' => 12, 'sira' => 1],
        'afternoon' => ['ad' => 'Öğleden sonra', 'bas' => 12, 'bit' => 17, 'sira' => 2],
        'evening'   => ['ad' => 'Akşam',         'bas' => 17, 'bit' => 20, 'sira' => 3],
    ];

    /** @return array<string, mixed>|null */
    public static function satirdan(object $k, DateTimeInterface $simdi): ?array
    {
        if (! preg_match('/\[gap:([a-z]+)\]/', (string) $k->kampanya_detay, $m) || ! isset(self::BOSLUKLAR[$m[1]])) {
            return null;
        }
        $bosluk = self::BOSLUKLAR[$m[1]];
        $aciklama = (string) $k->kampanya_aciklama;
        [$bas, $bit] = preg_match('/\((\d{2}):\d{2}-(\d{2}):\d{2}\)/', $aciklama, $s)
            ? [(int) $s[1], (int) $s[2]]
            : [$bosluk['bas'], $bosluk['bit']];
        $bitis = new DateTimeImmutable((string) $k->kampanya_bitis_tarihi, $simdi->getTimezone());

        return [
            'id'          => (int) $k->id,
            'gapKey'      => $m[1],
            'gapLabel'    => $bosluk['ad'],
            'startHour'   => $bas,
            'endHour'     => $bit,
            'discount'    => self::indirim($aciklama, (string) $k->kampanya_baslik),
            'baslik'      => $k->kampanya_baslik,
            'bitisTarihi' => $k->kampanya_bitis_tarihi,
            'kalanGun'    => max(0, (int) $simdi->diff($bitis)->format('%r%a')),
            'order'       => $bosluk['sira'],
        ];
    }

    private static function indirim(string $aciklama, string $baslik): int
    {
        if (preg_match('/%%?(\d{1,2})\s*indirim/iu', $aciklama, $m) || preg_match('/%%?(\d{1,2})/u', $baslik, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
