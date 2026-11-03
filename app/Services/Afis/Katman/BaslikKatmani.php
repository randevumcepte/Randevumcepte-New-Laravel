<?php

namespace App\Services\Afis\Katman;

use App\Domain\Afis\AfisIcerigi;

/** Üst bardaki işletme adı (logonun sağı). Sığmazsa 40px → 26px küçülür, sonra … ile kırpılır. */
final class BaslikKatmani implements AfisKatmani
{
    private const X = 236;

    private const MERKEZ_Y = 113;

    private const MAKS_GENISLIK = 780;

    private const BOYUT = 40;

    private const MIN_BOYUT = 26;

    private const RENK = [0x46, 0x34, 0x3C];

    public function ciz(\GdImage $tuval, AfisIcerigi $icerik): void
    {
        $font = AfisYazi::font(AfisYazi::SEMIBOLD);
        if ($icerik->ad === '' || $font === null) {
            return;
        }
        [$metin, $boyut] = AfisYazi::sigdir($font, $icerik->ad, self::BOYUT, self::MAKS_GENISLIK, self::MIN_BOYUT, 2);
        AfisYazi::solaYasliCiz($tuval, $font, $boyut, $metin, self::X, self::MERKEZ_Y, imagecolorallocate($tuval, ...self::RENK));
    }
}
