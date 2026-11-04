<?php

namespace App\Services\Hesabim\Hizmet;

use App\Domain\Hesabim\WhatsappHizmetKarti;

/** WhatsApp kartı — kural Domain'de; burada yalnız bugün + iletişim numarası verilir. */
class WhatsappKaynagi implements HizmetKaynagi
{
    public function kartlar(object $salon): array
    {
        return [WhatsappHizmetKarti::olustur($salon, now()->toDateString(), (string) config('hesabim.iletisim_tel'))];
    }
}
