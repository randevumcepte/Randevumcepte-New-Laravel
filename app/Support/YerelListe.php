<?php

namespace App\Support;

/**
 * Bellekte (PHP) hesaplanmis liste uzerinde ListeIstegi sozlesmesini uygular: ara + sirala
 * + sayfala + meta. Toplanmis rapor satirlari (kalem/personel bazli) SQL'de sayfalanamaz;
 * her rapor ucu bunu tekrar yazmasin diye TEK yer.
 */
final class YerelListe
{
    /**
     * @param  array<int,array<string,mixed>>  $satirlar
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $o  ListeIstegi::dizi()
     * @param  array<int,string>  $araAlanlari  aramanin kapsadigi (gorunen) alanlar
     * @return array{data: array, current_page:int, last_page:int, per_page:int, total:int}
     */
    public static function uygula(array $satirlar, array $o, array $araAlanlari): array
    {
        if (($o['ara'] ?? null) !== null) {
            $ara = self::normal((string) $o['ara']);
            $satirlar = array_values(array_filter($satirlar, function ($s) use ($ara, $araAlanlari) {
                $metin = implode(' ', array_map(fn ($a) => (string) ($s[$a] ?? ''), $araAlanlari));

                return str_contains(self::normal($metin), $ara);
            }));
        }

        if ($alan = $o['sirala'] ?? null) {
            $c = ($o['yon'] ?? 'desc') === 'asc' ? 1 : -1;
            usort($satirlar, function ($a, $b) use ($alan, $c) {
                $x = $a[$alan] ?? null;
                $y = $b[$alan] ?? null;

                return (is_numeric($x) && is_numeric($y) ? $x <=> $y : strcmp(self::normal((string) $x), self::normal((string) $y))) * $c;
            });
        }

        $toplam = count($satirlar);
        $meta = ListeIstegi::meta($o['page'], $o['per_page'], $toplam);
        $sayfa = min(max(1, $o['page']), $meta['last_page']);

        return ['data' => array_slice($satirlar, ($sayfa - 1) * $o['per_page'], $o['per_page'])]
            + array_merge($meta, ['current_page' => $sayfa]);
    }

    /** Buyuk/kucuk + Turkce harf + aksan duyarsiz karsilastirma (web v2-tablo.js normal() ile ayni). */
    private static function normal(string $s): string
    {
        $s = mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $s), 'UTF-8');

        return strtr($s, ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c']);
    }
}
