<?php

namespace App\Domain\SistemYonetim;

/** sms_paket_siparisleri.fatura_durumu */
enum SiparisFaturaDurumu: int
{
    use EtiketliSecenek;

    case Kesilmedi = 0;
    case Kesildi = 1;
    case Hata = 2;

    public function etiket(): string
    {
        return match ($this) {
            self::Kesilmedi => 'Kesilmedi',
            self::Kesildi   => 'Kesildi',
            self::Hata      => 'Hata',
        };
    }

    public function renk(): string
    {
        return match ($this) {
            self::Kesilmedi => 'muted',
            self::Kesildi   => 'success',
            self::Hata      => 'danger',
        };
    }
}
