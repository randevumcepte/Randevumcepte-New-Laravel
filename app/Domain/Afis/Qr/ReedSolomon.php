<?php

namespace App\Domain\Afis\Qr;

/**
 * QR hata düzeltme baytları — GF(2^8), ilkel polinom 0x11D, üreteç kökleri α^0 … α^(n-1)
 * (ISO/IEC 18004 §7.5.2). Saf hesap; durum yalnız sabit log/üs tabloları.
 */
final class ReedSolomon
{
    /** @var list<int> */
    private static array $us = [];

    /** @var list<int> */
    private static array $log = [];

    /** @var array<int, list<int>> ec sayısı → üreteç polinomu katsayıları (yüksek dereceden) */
    private static array $ureteclar = [];

    /**
     * @param  list<int>  $veri  veri baytları
     * @return list<int>         $ecSayisi adet hata düzeltme baytı
     */
    public static function ecBaytlari(array $veri, int $ecSayisi): array
    {
        $g = self::uretec($ecSayisi);
        $kalan = array_merge($veri, array_fill(0, $ecSayisi, 0));

        $n = count($veri);
        for ($i = 0; $i < $n; $i++) {
            $katsayi = $kalan[$i];
            if ($katsayi === 0) {
                continue;
            }
            for ($j = 1; $j <= $ecSayisi; $j++) {
                $kalan[$i + $j] ^= self::carp($g[$j], $katsayi);
            }
        }

        return array_slice($kalan, $n);
    }

    /** @return list<int> (x - α^0)(x - α^1)…(x - α^(n-1)), baş katsayı 1 */
    private static function uretec(int $n): array
    {
        if (isset(self::$ureteclar[$n])) {
            return self::$ureteclar[$n];
        }
        self::tablolar();

        $g = [1];
        for ($i = 0; $i < $n; $i++) {
            $yeni = array_fill(0, count($g) + 1, 0);
            foreach ($g as $k => $c) {
                $yeni[$k] ^= $c;
                $yeni[$k + 1] ^= self::carp($c, self::$us[$i]);
            }
            $g = $yeni;
        }

        return self::$ureteclar[$n] = $g;
    }

    private static function carp(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        self::tablolar();

        return self::$us[(self::$log[$a] + self::$log[$b]) % 255];
    }

    private static function tablolar(): void
    {
        if (self::$us !== []) {
            return;
        }
        $x = 1;
        $us = $log = array_fill(0, 256, 0);
        for ($i = 0; $i < 255; $i++) {
            $us[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        self::$us = $us;
        self::$log = $log;
    }
}
