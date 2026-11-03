<?php

namespace App\Domain\Afis\Qr;

/**
 * Maske ceza puanı (ISO/IEC 18004 §7.8.3; ağırlıklar N1=3, N2=3, N3=40, N4=10).
 * Kural uygulaması ZXing / BaconQrCode ile BİREBİR — aynı matris aynı maskeyi seçsin diye.
 */
final class QrMaskeCezasi
{
    /** @param  list<list<int>>  $m */
    public static function puan(array $m): int
    {
        return self::ardisik($m) + self::bloklar($m) + self::buluculBenzeri($m) + self::denge($m);
    }

    /** Kural 1: satır/sütunda 5+ aynı renk → 3 + (uzunluk - 5). */
    private static function ardisik(array $m): int
    {
        $n = count($m);
        $ceza = 0;
        foreach ([true, false] as $yatay) {
            for ($i = 0; $i < $n; $i++) {
                $sayac = 0;
                $onceki = -1;
                for ($j = 0; $j < $n; $j++) {
                    $bit = $yatay ? $m[$i][$j] : $m[$j][$i];
                    if ($bit === $onceki) {
                        $sayac++;

                        continue;
                    }
                    if ($sayac >= 5) {
                        $ceza += 3 + ($sayac - 5);
                    }
                    $sayac = 1;
                    $onceki = $bit;
                }
                if ($sayac >= 5) {
                    $ceza += 3 + ($sayac - 5);
                }
            }
        }

        return $ceza;
    }

    /** Kural 2: her 2x2 tek renk blok → 3. */
    private static function bloklar(array $m): int
    {
        $n = count($m);
        $adet = 0;
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $v = $m[$y][$x];
                if ($v === $m[$y][$x + 1] && $v === $m[$y + 1][$x] && $v === $m[$y + 1][$x + 1]) {
                    $adet++;
                }
            }
        }

        return 3 * $adet;
    }

    /** Kural 3: 1011101 deseni + bir yanında 4 açık modül → 40 (yatay ve dikey). */
    private static function buluculBenzeri(array $m): int
    {
        $n = count($m);
        $ceza = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (self::desenVar(fn (int $k) => $m[$y][$x + $k] ?? null, $x, $n)) {
                    $ceza += 40;
                }
                if (self::desenVar(fn (int $k) => isset($m[$y + $k]) ? $m[$y + $k][$x] : null, $y, $n)) {
                    $ceza += 40;
                }
            }
        }

        return $ceza;
    }

    /** @param  \Closure(int):?int  $hucre  başlangıçtan $k uzaklıktaki modül (taşarsa null) */
    private static function desenVar(\Closure $hucre, int $konum, int $n): bool
    {
        if ($konum + 6 >= $n) {
            return false;
        }
        foreach ([1, 0, 1, 1, 1, 0, 1] as $k => $beklenen) {
            if ($hucre($k) !== $beklenen) {
                return false;
            }
        }
        $acik = fn (array $araliklar) => array_reduce($araliklar, fn ($ok, $k) => $ok && $hucre($k) === 0, true);

        return ($konum + 10 < $n && $acik([7, 8, 9, 10]))
            || ($konum - 4 >= 0 && $acik([-1, -2, -3, -4]));
    }

    /** Kural 4: koyu oranının %50'den her %5 sapması → 10. */
    private static function denge(array $m): int
    {
        $n = count($m);
        $koyu = 0;
        foreach ($m as $satir) {
            $koyu += array_sum($satir);
        }

        return (int) (abs($koyu / ($n * $n) - 0.5) * 20) * 10;
    }
}
