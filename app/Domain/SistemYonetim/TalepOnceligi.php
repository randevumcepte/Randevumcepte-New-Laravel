<?php

namespace App\Domain\SistemYonetim;

enum TalepOnceligi: string
{
    use EtiketliSecenek;

    case Dusuk = 'dusuk';
    case Orta = 'orta';
    case Yuksek = 'yuksek';
    case Acil = 'acil';

    public function etiket(): string
    {
        return match ($this) {
            self::Dusuk  => 'Düşük',
            self::Orta   => 'Orta',
            self::Yuksek => 'Yüksek',
            self::Acil   => 'Acil',
        };
    }

    public function renk(): string
    {
        return match ($this) {
            self::Dusuk  => 'muted',
            self::Orta   => 'info',
            self::Yuksek => 'warning',
            self::Acil   => 'danger',
        };
    }

    /** Listede en acil olan once. @return list<string> */
    public static function aciliyetSirasi(): array
    {
        return array_reverse(array_column(self::cases(), 'value'));
    }
}
