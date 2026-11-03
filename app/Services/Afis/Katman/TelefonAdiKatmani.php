<?php

namespace App\Services\Afis\Katman;

use App\Domain\Afis\AfisIcerigi;

/**
 * Telefon mockup'ındaki uygulama ekranının üst "salon adı" hapı. Arka plana gömülü demo
 * salon adı ekran rengiyle örtülür, yerine işletmenin adıyla lavanta bir hap çizilir
 * (eski afisTelefonAdiYaz ile aynı geometri).
 */
final class TelefonAdiKatmani implements AfisKatmani
{
    private const ORTU = [985, 292, 1170, 340];   // demo hapın kapatılacak alanı (x0, y0, x1, y1)

    private const ORTU_RENK = [243, 239, 240];

    private const MERKEZ = [1077, 316];

    private const YUKSEKLIK = 40;

    private const YATAY_BOSLUK = 26;

    private const MAKS_HAP_GENISLIK = 210;

    private const METIN_PX = 20;                  // hedef glif yüksekliği (FreeType ölçeği ortama göre değişir)

    private const MAKS_KARAKTER = 22;

    private const HAP_RENK = [233, 220, 227];

    private const YAZI_RENK = [0x6B, 0x50, 0x5A];

    public function ciz(\GdImage $tuval, AfisIcerigi $icerik): void
    {
        $font = AfisYazi::font(AfisYazi::SEMIBOLD);
        if ($icerik->ad === '' || $font === null) {
            return;
        }

        $ad = mb_strlen($icerik->ad, 'UTF-8') > self::MAKS_KARAKTER
            ? mb_substr($icerik->ad, 0, self::MAKS_KARAKTER - 1, 'UTF-8').'…'
            : $icerik->ad;

        // Glif yüksekliğini METIN_PX'e normalize et, sonra hap MAKS_HAP_GENISLIK'e sığana dek küçült.
        $boyut = 20;
        $h = AfisYazi::olc($font, $boyut, $ad)['yukseklik'];
        if ($h > 0) {
            $boyut = max(8, (int) round($boyut * self::METIN_PX / $h));
        }
        while ($boyut > 9 && AfisYazi::olc($font, $boyut, $ad)['genislik'] + self::YATAY_BOSLUK * 2 > self::MAKS_HAP_GENISLIK) {
            $boyut--;
        }
        $hapGenislik = AfisYazi::olc($font, $boyut, $ad)['genislik'] + self::YATAY_BOSLUK * 2;

        [$x0, $y0, $x1, $y1] = self::ORTU;
        imagefilledrectangle($tuval, $x0, $y0, $x1, $y1, imagecolorallocate($tuval, ...self::ORTU_RENK));

        [$cx, $cy] = self::MERKEZ;
        $r = (int) (self::YUKSEKLIK / 2);
        $sol = (int) ($cx - $hapGenislik / 2);
        $sag = (int) ($cx + $hapGenislik / 2);
        $ust = (int) ($cy - self::YUKSEKLIK / 2);
        $hap = imagecolorallocate($tuval, ...self::HAP_RENK);
        imagefilledrectangle($tuval, $sol + $r, $ust, $sag - $r, $ust + self::YUKSEKLIK, $hap);
        imagefilledellipse($tuval, $sol + $r, $cy, self::YUKSEKLIK, self::YUKSEKLIK, $hap);
        imagefilledellipse($tuval, $sag - $r, $cy, self::YUKSEKLIK, self::YUKSEKLIK, $hap);

        AfisYazi::ortalaCiz($tuval, $font, $boyut, $ad, $cx, $cy, imagecolorallocate($tuval, ...self::YAZI_RENK));
    }
}
