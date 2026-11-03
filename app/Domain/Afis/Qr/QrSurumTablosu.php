<?php

namespace App\Domain\Afis\Qr;

/**
 * QR sürüm kapasiteleri — YALNIZ hata düzeltme seviyesi M (%15), sürüm 1–10
 * (ISO/IEC 18004 Tablo 9 + Ek E hizalama merkezleri).
 *
 * Sürüm 10 / M bayt modunda 213 bayta kadar taşır; afiş / indirme linki gibi kısa URL'ler
 * için fazlasıyla yeterli. Daha uzun içerik gerekirse tabloya satır eklemek yeter (OCP) —
 * kodlayıcı tablonun boyutunu bilmez.
 */
final class QrSurumTablosu
{
    /** Hata düzeltme seviyesi M'nin biçim bitleri (L=01, M=00, Q=11, H=10). */
    public const SEVIYE_BITLERI = 0b00;

    /** sürüm => [toplam kod sözcüğü, blok başına EC sözcüğü, blok sayısı, hizalama merkezleri] */
    private const SURUMLER = [
        1  => [26,  10, 1, []],
        2  => [44,  16, 1, [6, 18]],
        3  => [70,  26, 1, [6, 22]],
        4  => [100, 18, 2, [6, 26]],
        5  => [134, 24, 2, [6, 30]],
        6  => [172, 16, 4, [6, 34]],
        7  => [196, 18, 4, [6, 22, 38]],
        8  => [242, 22, 4, [6, 24, 42]],
        9  => [292, 22, 5, [6, 26, 46]],
        10 => [346, 26, 5, [6, 28, 50]],
    ];

    public static function enBuyukSurum(): int
    {
        return array_key_last(self::SURUMLER);
    }

    public static function toplamSozcuk(int $surum): int
    {
        return self::satir($surum)[0];
    }

    public static function veriSozcugu(int $surum): int
    {
        [$toplam, $ecBlok, $blok] = self::satir($surum);

        return $toplam - $ecBlok * $blok;
    }

    public static function blokSayisi(int $surum): int
    {
        return self::satir($surum)[2];
    }

    /** @return list<int> */
    public static function hizalamaMerkezleri(int $surum): array
    {
        return self::satir($surum)[3];
    }

    public static function boyut(int $surum): int
    {
        return 17 + 4 * $surum;
    }

    /** Bayt modunda karakter sayısı alanının bit genişliği (sürüm 1–9: 8, 10–26: 16). */
    public static function uzunlukBiti(int $surum): int
    {
        return $surum <= 9 ? 8 : 16;
    }

    /** @return array{0:int,1:int,2:int,3:list<int>} */
    private static function satir(int $surum): array
    {
        return self::SURUMLER[$surum] ?? throw new \InvalidArgumentException("Desteklenmeyen QR sürümü: {$surum}");
    }
}
