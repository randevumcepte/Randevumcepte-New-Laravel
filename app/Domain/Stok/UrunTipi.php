<?php

namespace App\Domain\Stok;

/** Urun kullanim tipi (urunler.tip): satilir / hizmette sarf edilir / ikisi. */
enum UrunTipi: string
{
    case Satis = 'satis';
    case Sarf = 'sarf';
    case Karma = 'karma';

    public function etiket(): string
    {
        return match ($this) {
            self::Satis => 'Satış',
            self::Sarf  => 'Sarf',
            self::Karma => 'Karma',
        };
    }

    /**
     * Musteriye satilabilir mi (Satis / Karma)? Sarf yalnizca hizmette tuketilir. Bos / bilinmeyen
     * deger = Satis (kolon varsayilani 'satis'; eski urunler).
     */
    public static function satilabilirMi(?string $tip): bool
    {
        return (self::tryFrom((string) $tip) ?? self::Satis) !== self::Sarf;
    }

    /** [{deger, etiket}] */
    public static function secenekler(): array
    {
        return array_map(fn (self $t) => ['deger' => $t->value, 'etiket' => $t->etiket()], self::cases());
    }
}
