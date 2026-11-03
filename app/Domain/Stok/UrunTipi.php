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

    /** [{deger, etiket}] */
    public static function secenekler(): array
    {
        return array_map(fn (self $t) => ['deger' => $t->value, 'etiket' => $t->etiket()], self::cases());
    }
}
