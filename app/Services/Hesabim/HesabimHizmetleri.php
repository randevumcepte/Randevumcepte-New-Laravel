<?php

namespace App\Services\Hesabim;

use App\Domain\Hesabim\HizmetKarti;
use App\Services\Hesabim\Hizmet\CarkKaynagi;
use App\Services\Hesabim\Hizmet\HizmetKaynagi;
use App\Services\Hesabim\Hizmet\OnamFormKaynagi;
use App\Services\Hesabim\Hizmet\PremiumKaynagi;
use App\Services\Hesabim\Hizmet\UyelikKaynagi;
use App\Services\Hesabim\Hizmet\WhatsappKaynagi;

/** "Aldığım Hizmetler" listesi — kaynakları sırayla birleştirir; kart kuralı kaynaklarda. */
class HesabimHizmetleri
{
    /** @var list<HizmetKaynagi> */
    private array $kaynaklar;

    public function __construct(
        UyelikKaynagi $uyelik,
        WhatsappKaynagi $whatsapp,
        PremiumKaynagi $premium,
        CarkKaynagi $cark,
        OnamFormKaynagi $onam,
    ) {
        $this->kaynaklar = [$uyelik, $whatsapp, $premium, $cark, $onam];
    }

    /** @return list<array<string,mixed>> */
    public function listele(object $salon): array
    {
        $kartlar = array_merge(...array_map(fn (HizmetKaynagi $k) => $k->kartlar($salon), $this->kaynaklar));

        return array_map(fn (HizmetKarti $k) => $k->dizi(), $kartlar);
    }
}
