<?php

namespace App\Domain\SistemYonetim;

/** sms_paket_siparisleri.durum */
enum SiparisOdemeDurumu: int
{
    use EtiketliSecenek;

    case Beklemede = 0;
    case Odendi = 1;
    case Basarisiz = 2;

    public function etiket(): string
    {
        return match ($this) {
            self::Beklemede => 'Beklemede',
            self::Odendi    => 'Ödendi',
            self::Basarisiz => 'Başarısız',
        };
    }

    public function renk(): string
    {
        return match ($this) {
            self::Beklemede => 'warning',
            self::Odendi    => 'success',
            self::Basarisiz => 'danger',
        };
    }
}
