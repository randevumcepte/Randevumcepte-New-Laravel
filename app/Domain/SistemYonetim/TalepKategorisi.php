<?php

namespace App\Domain\SistemYonetim;

enum TalepKategorisi: string
{
    use EtiketliSecenek;

    case Teknik = 'teknik';
    case Odeme = 'odeme';
    case Egitim = 'egitim';
    case Ozellik = 'ozellik';
    case Sikayet = 'sikayet';
    case Diger = 'diger';

    public function etiket(): string
    {
        return match ($this) {
            self::Teknik  => 'Teknik',
            self::Odeme   => 'Ödeme',
            self::Egitim  => 'Eğitim',
            self::Ozellik => 'Özellik İsteği',
            self::Sikayet => 'Şikayet',
            self::Diger   => 'Diğer',
        };
    }

    public function renk(): string
    {
        return 'muted';
    }
}
