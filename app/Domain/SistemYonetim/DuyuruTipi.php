<?php

namespace App\Domain\SistemYonetim;

enum DuyuruTipi: string
{
    use EtiketliSecenek;

    case Guncelleme = 'guncelleme';
    case Bilgi = 'bilgi';
    case Uyari = 'uyari';
    case Onemli = 'onemli';
    case Bakim = 'bakim';
    case Kampanya = 'kampanya';

    public function etiket(): string
    {
        return match ($this) {
            self::Guncelleme => '🚀 Güncelleme',
            self::Bilgi      => 'Bilgi',
            self::Uyari      => 'Uyarı',
            self::Onemli     => 'Önemli',
            self::Bakim      => 'Bakım',
            self::Kampanya   => 'Kampanya',
        };
    }

    public function renk(): string
    {
        return match ($this) {
            self::Guncelleme, self::Bilgi => 'info',
            self::Uyari    => 'warning',
            self::Onemli   => 'danger',
            self::Bakim    => 'muted',
            self::Kampanya => 'success',
        };
    }

    /** Formda salonda nasil gorunecegi de yazar. */
    public function formEtiketi(): string
    {
        return match ($this) {
            self::Guncelleme => '🚀 Güncelleme / Yenilik · ortada popup',
            self::Bilgi      => 'Bilgi · mavi',
            self::Uyari      => 'Uyarı · sarı',
            self::Onemli     => 'Önemli · kırmızı',
            self::Bakim      => 'Bakım · gri',
            self::Kampanya   => 'Kampanya · yeşil',
        };
    }

    /** @return list<array{kod:string,etiket:string}> */
    public static function formSecenekleri(): array
    {
        return array_map(fn (self $d) => ['kod' => $d->value, 'etiket' => $d->formEtiketi()], self::cases());
    }
}
