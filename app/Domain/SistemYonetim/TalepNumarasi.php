<?php

namespace App\Domain\SistemYonetim;

use DateTimeInterface;

/** T-yymmdd-XXXX. Rastgele kisim tek basina tekil degildir; cakisma kaydeden tarafta yakalanir. */
class TalepNumarasi
{
    public function uret(DateTimeInterface $zaman): string
    {
        return 'T-'.$zaman->format('ymd').'-'.strtoupper(bin2hex(random_bytes(2)));
    }
}
