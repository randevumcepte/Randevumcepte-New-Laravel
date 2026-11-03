<?php

namespace App\Services\Afis\Katman;

/**
 * Katmanların ORTAK metin ölçme / sığdırma / çizme yardımcısı (GD + FreeType). Font dosyası
 * çözümü ve "kutuya sığmazsa küçült, yine sığmazsa … ile kırp" kuralı TEK yerde.
 */
final class AfisYazi
{
    public const SEMIBOLD = 'Poppins-SemiBold.ttf';

    public const BOLD = 'Poppins-Bold.ttf';

    /** @return string|null font yolu (dosya yoksa null → katman sessizce atlar, eski davranış) */
    public static function font(string $ad): ?string
    {
        $yol = rtrim((string) config('afis.kaynak_dizin'), '/').'/'.$ad;

        return is_file($yol) ? $yol : null;
    }

    /** @return array{genislik: float, yukseklik: float, sol: int, ust: int} */
    public static function olc(string $font, float $boyut, string $metin): array
    {
        $bb = imagettfbbox($boyut, 0, $font, $metin) ?: [0, 0, 0, 0, 0, 0, 0, 0];

        return ['genislik' => abs($bb[2] - $bb[0]), 'yukseklik' => $bb[1] - $bb[7], 'sol' => $bb[0], 'ust' => $bb[7]];
    }

    /**
     * Metni $maksGenislik'e sığdırır: önce $adim adımlarla $minBoyut'a kadar küçültür, yine
     * sığmıyorsa sondan kırpıp '…' ekler.
     *
     * @return array{0: string, 1: float} [metin, boyut]
     */
    public static function sigdir(string $font, string $metin, float $boyut, float $maksGenislik, float $minBoyut, float $adim): array
    {
        while ($boyut > $minBoyut && self::olc($font, $boyut, $metin)['genislik'] > $maksGenislik) {
            $boyut -= $adim;
        }

        if (self::olc($font, $boyut, $metin)['genislik'] <= $maksGenislik) {
            return [$metin, $boyut];
        }
        $kirpik = $metin;
        while (mb_strlen($kirpik, 'UTF-8') > 4) {
            $kirpik = mb_substr($kirpik, 0, -1, 'UTF-8');
            if (self::olc($font, $boyut, $kirpik.'…')['genislik'] <= $maksGenislik) {
                break;
            }
        }

        return [$kirpik === $metin ? $metin : $kirpik.'…', $boyut];
    }

    /** Metni, sol kenarı $x ve dikey merkezi $cy olacak şekilde çizer. */
    public static function solaYasliCiz(\GdImage $tuval, string $font, float $boyut, string $metin, int $x, float $cy, int $renk): void
    {
        $o = self::olc($font, $boyut, $metin);
        imagettftext($tuval, $boyut, 0, $x, (int) ($cy - $o['yukseklik'] / 2 - $o['ust']), $renk, $font, $metin);
    }

    /** Metni ($cx, $cy) merkezine ortalayarak çizer. */
    public static function ortalaCiz(\GdImage $tuval, string $font, float $boyut, string $metin, float $cx, float $cy, int $renk): void
    {
        $o = self::olc($font, $boyut, $metin);
        imagettftext($tuval, $boyut, 0, (int) ($cx - $o['genislik'] / 2 - $o['sol']), (int) ($cy - $o['yukseklik'] / 2 - $o['ust']), $renk, $font, $metin);
    }
}
