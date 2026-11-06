<?php

namespace App\Domain\SistemYonetim;

enum DuyuruHedefTipi: string
{
    use EtiketliSecenek;

    case Hepsi = 'hepsi';
    case Secili = 'secili';
    case Il = 'il';

    public function etiket(): string
    {
        return match ($this) {
            self::Hepsi  => 'Tüm Salonlar',
            self::Secili => 'Seçili Salonlar',
            self::Il     => 'İle Göre',
        };
    }

    public function renk(): string
    {
        return 'muted';
    }
}
