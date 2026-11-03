<?php

namespace App\Services\Afis\Katman;

use App\Domain\Afis\AfisIcerigi;
use App\Domain\Afis\Qr\QrKodlayici;

/** İndirme linkinin QR kodu — arka plandaki beyaz kartın içine (eski afisQrCiz ile aynı yer/renk). */
final class QrKatmani implements AfisKatmani
{
    private const X = 380;

    private const Y = 412;

    private const KENAR = 340;            // sessiz alan dahil kare kenarı

    private const SESSIZ_ALAN = 2;        // modül

    private const RENK = [0x46, 0x34, 0x3C];

    public function ciz(\GdImage $tuval, AfisIcerigi $icerik): void
    {
        $qr = QrKodlayici::kodla($icerik->indirLinki);
        $n = $qr->boyut();
        $hucre = self::KENAR / ($n + self::SESSIZ_ALAN * 2);
        $renk = imagecolorallocate($tuval, ...self::RENK);

        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (! $qr->koyuMu($x, $y)) {
                    continue;
                }
                $px = self::X + ($x + self::SESSIZ_ALAN) * $hucre;
                $py = self::Y + ($y + self::SESSIZ_ALAN) * $hucre;
                imagefilledrectangle($tuval, (int) floor($px), (int) floor($py), (int) ceil($px + $hucre), (int) ceil($py + $hucre), $renk);
            }
        }
    }
}
