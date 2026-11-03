<?php

namespace App\Domain\Afis\Qr;

/**
 * QR modül yerleşimi (ISO/IEC 18004 §7.7–7.9): bulucu/ayırıcı/zamanlama/hizalama desenleri,
 * biçim + sürüm bilgisi, zikzak veri yerleşimi ve maske. Saf: girdi bitler → [$y][$x] 0|1.
 * Yerleşim sırası ZXing / BaconQrCode ile BİREBİR (aynı girdi → aynı matris).
 */
final class QrYerlesim
{
    private const BOS = -1;

    private const BICIM_POLINOMU = 0x537;

    private const BICIM_MASKESI = 0x5412;

    private const SURUM_POLINOMU = 0x1F25;

    /** Biçim bitlerinin sol üst kopyası (x, y) — bit 14 → 0 sırasıyla. */
    private const BICIM_KOORDINATLARI = [
        [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
        [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
    ];

    /**
     * @param  list<bool>  $bitler  araya serilmiş veri + EC bitleri
     * @return list<list<int>>
     */
    public static function insaEt(array $bitler, int $surum, int $maske): array
    {
        $n = QrSurumTablosu::boyut($surum);
        $m = array_fill(0, $n, array_fill(0, $n, self::BOS));

        self::bulucular($m, $n);
        $m[$n - 8][8] = 1;                       // sabit koyu modül
        self::hizalamalar($m, $surum);
        self::zamanlama($m, $n);
        self::bicimBilgisi($m, $n, $maske);
        self::surumBilgisi($m, $n, $surum);
        self::veri($m, $n, $bitler, $maske);

        return $m;
    }

    public static function maskeBiti(int $maske, int $x, int $y): bool
    {
        $k = match ($maske) {
            0 => ($y + $x) & 1,
            1 => $y & 1,
            2 => $x % 3,
            3 => ($y + $x) % 3,
            4 => (($y >> 1) + intdiv($x, 3)) & 1,
            5 => (($y * $x) & 1) + (($y * $x) % 3),
            6 => ((($y * $x) & 1) + (($y * $x) % 3)) & 1,
            7 => ((($y * $x) % 3) + (($y + $x) & 1)) & 1,
            default => throw new \InvalidArgumentException("Geçersiz maske: {$maske}"),
        };

        return $k === 0;
    }

    private static function bulucular(array &$m, int $n): void
    {
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x0, $y0]) {
            for ($y = 0; $y < 7; $y++) {
                for ($x = 0; $x < 7; $x++) {
                    $kenar = $x === 0 || $x === 6 || $y === 0 || $y === 6;
                    $merkez = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
                    $m[$y0 + $y][$x0 + $x] = ($kenar || $merkez) ? 1 : 0;
                }
            }
        }
        // Ayırıcılar (açık modül şeritleri)
        for ($i = 0; $i < 8; $i++) {
            $m[7][$i] = $m[7][$n - 8 + $i] = $m[$n - 8][$i] = 0;
        }
        for ($i = 0; $i < 7; $i++) {
            $m[$i][7] = $m[$i][$n - 8] = $m[$n - 7 + $i][7] = 0;
        }
    }

    private static function hizalamalar(array &$m, int $surum): void
    {
        $merkezler = QrSurumTablosu::hizalamaMerkezleri($surum);
        foreach ($merkezler as $cy) {
            foreach ($merkezler as $cx) {
                if ($m[$cy][$cx] !== self::BOS) {
                    continue;                    // bulucu desenle çakışan köşeler atlanır
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $m[$cy + $dy][$cx + $dx] = (max(abs($dx), abs($dy)) !== 1) ? 1 : 0;
                    }
                }
            }
        }
    }

    private static function zamanlama(array &$m, int $n): void
    {
        for ($i = 8; $i < $n - 8; $i++) {
            $bit = ($i + 1) % 2;
            if ($m[6][$i] === self::BOS) {
                $m[6][$i] = $bit;
            }
            if ($m[$i][6] === self::BOS) {
                $m[$i][6] = $bit;
            }
        }
    }

    private static function bicimBilgisi(array &$m, int $n, int $maske): void
    {
        $deger = (QrSurumTablosu::SEVIYE_BITLERI << 3) | $maske;
        $bitler = (($deger << 10) | self::bch($deger, self::BICIM_POLINOMU)) ^ self::BICIM_MASKESI;

        for ($i = 0; $i < 15; $i++) {
            $bit = ($bitler >> $i) & 1;          // i. yerleşim = (14 - i). sıradaki bit (MSB önce)
            [$x1, $y1] = self::BICIM_KOORDINATLARI[$i];
            $m[$y1][$x1] = $bit;
            if ($i < 8) {
                $m[8][$n - $i - 1] = $bit;
            } else {
                $m[$n - 7 + ($i - 8)][8] = $bit;
            }
        }
    }

    private static function surumBilgisi(array &$m, int $n, int $surum): void
    {
        if ($surum < 7) {
            return;
        }
        $bitler = ($surum << 12) | self::bch($surum, self::SURUM_POLINOMU);
        $indeks = 0;
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $bit = ($bitler >> $indeks++) & 1;
                $m[$n - 11 + $j][$i] = $bit;
                $m[$i][$n - 11 + $j] = $bit;
            }
        }
    }

    /** @param  list<bool>  $bitler */
    private static function veri(array &$m, int $n, array $bitler, int $maske): void
    {
        $indeks = 0;
        $toplam = count($bitler);
        $yon = -1;
        $y = $n - 1;

        for ($x = $n - 1; $x > 0; $x -= 2) {
            if ($x === 6) {
                $x--;                            // dikey zamanlama sütunu atlanır
            }
            for (; $y >= 0 && $y < $n; $y += $yon) {
                for ($i = 0; $i < 2; $i++) {
                    $xx = $x - $i;
                    if ($m[$y][$xx] !== self::BOS) {
                        continue;
                    }
                    $bit = $indeks < $toplam ? $bitler[$indeks++] : false;
                    if (self::maskeBiti($maske, $xx, $y)) {
                        $bit = ! $bit;
                    }
                    $m[$y][$xx] = $bit ? 1 : 0;
                }
            }
            $yon = -$yon;
            $y += $yon;
        }
    }

    /** BCH kalanı: $deger * x^(derece) mod $polinom. */
    private static function bch(int $deger, int $polinom): int
    {
        $derece = self::msb($polinom) - 1;
        $deger <<= $derece;
        while (self::msb($deger) > $derece) {
            $deger ^= $polinom << (self::msb($deger) - $derece - 1);
        }

        return $deger;
    }

    private static function msb(int $v): int
    {
        $n = 0;
        while ($v !== 0) {
            $v >>= 1;
            $n++;
        }

        return $n;
    }
}
