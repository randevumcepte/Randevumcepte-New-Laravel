<?php

namespace App\Domain\SistemYonetim;

enum HazirCevapKategorisi: string
{
    use EtiketliSecenek;

    case Genel = 'genel';
    case Teknik = 'teknik';
    case Odeme = 'odeme';
    case Egitim = 'egitim';
    case Iade = 'iade';
    case Kapanis = 'kapanis';

    public function etiket(): string
    {
        return match ($this) {
            self::Genel   => 'Genel',
            self::Teknik  => 'Teknik',
            self::Odeme   => 'Ödeme',
            self::Egitim  => 'Eğitim',
            self::Iade    => 'İade',
            self::Kapanis => 'Kapanış',
        };
    }

    public function renk(): string
    {
        return match ($this) {
            self::Genel, self::Kapanis => 'muted',
            self::Teknik => 'info',
            self::Odeme  => 'warning',
            self::Egitim => 'success',
            self::Iade   => 'danger',
        };
    }
}
