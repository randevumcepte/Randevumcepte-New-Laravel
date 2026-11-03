<?php

namespace App\Domain\Stok;

/** Urun olcu birimi (urunler.birim) — eski formdaki secenekler. */
enum Birim: string
{
    case Adet = 'adet';
    case Gram = 'gr';
    case Kilogram = 'kg';
    case Mililitre = 'ml';
    case Litre = 'lt';
    case Paket = 'paket';

    public function etiket(): string
    {
        return match ($this) {
            self::Adet      => 'Adet',
            self::Gram      => 'Gram (gr)',
            self::Kilogram  => 'Kilogram (kg)',
            self::Mililitre => 'Mililitre (ml)',
            self::Litre     => 'Litre (lt)',
            self::Paket     => 'Paket',
        };
    }

    /** [{deger, etiket}] */
    public static function secenekler(): array
    {
        return array_map(fn (self $b) => ['deger' => $b->value, 'etiket' => $b->etiket()], self::cases());
    }
}
