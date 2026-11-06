<?php

namespace App\Domain\SistemYonetim;

/** Sistem yonetimi ekip rolleri. Hangi ucu kimin kullanacagi routes/v2/sistem*.php'de (sistem.rol). */
enum SistemRolu: string
{
    case SuperAdmin = 'super_admin';
    case Yonetici = 'yonetici';
    case Destek = 'destek';
    case Izleyici = 'izleyici';

    public function etiket(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Süper Admin',
            self::Yonetici   => 'Yönetici',
            self::Destek     => 'Destek',
            self::Izleyici   => 'İzleyici',
        };
    }

    /** Destek yalniz kendisine atanmis salonlari gorur. */
    public function salonKapsamliMi(): bool
    {
        return $this === self::Destek;
    }

    /** @return list<array{kod:string,etiket:string}> */
    public static function secenekler(): array
    {
        return array_map(fn (self $r) => ['kod' => $r->value, 'etiket' => $r->etiket()], self::cases());
    }
}
