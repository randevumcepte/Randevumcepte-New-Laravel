<?php

namespace App\Domain\SistemYonetim;

/** Salon ic notu tipleri (sistemyonetim_salon_notlari.tip). */
enum SalonNotTipi: string
{
    case Genel = 'genel';
    case Uyari = 'uyari';
    case Onemli = 'onemli';
    case Sikayet = 'sikayet';
    case Talep = 'talep';
    case Odeme = 'odeme';

    public function etiket(): string
    {
        return match ($this) {
            self::Genel   => 'Genel',
            self::Uyari   => 'Uyarı',
            self::Onemli  => 'Önemli',
            self::Sikayet => 'Şikayet',
            self::Talep   => 'Özellik Talebi',
            self::Odeme   => 'Ödeme',
        };
    }

    /** @return list<array{kod:string,etiket:string}> */
    public static function secenekler(): array
    {
        return array_map(fn (self $t) => ['kod' => $t->value, 'etiket' => $t->etiket()], self::cases());
    }
}
