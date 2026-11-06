<?php

namespace App\Support;

/** ListeIstegi sozlesmesini hazir diziye uygular: PHP'de hesaplanan kisa listeler (risk, performans, son olaylar). */
final class DiziSayfasi
{
    /**
     * @param  list<array>  $satirlar
     * @param  array{page:int,per_page:int,ara:?string,sirala:?string,yon:string}  $liste
     * @param  callable(array):string  $aramaMetni
     * @param  array<string,string>  $siralama  alan => satir anahtari
     * @return array{data:array,current_page:int,last_page:int,per_page:int,total:int}
     */
    public static function sayfala(array $satirlar, array $liste, callable $aramaMetni, array $siralama): array
    {
        $ara = self::normal((string) ($liste['ara'] ?? ''));
        if ($ara !== '') {
            $satirlar = array_values(array_filter($satirlar, fn (array $s) => str_contains(self::normal($aramaMetni($s)), $ara)));
        }

        $anahtar = $siralama[$liste['sirala'] ?? ''] ?? null;
        if ($anahtar !== null) {
            $yon = ($liste['yon'] ?? 'desc') === 'asc' ? 1 : -1;
            usort($satirlar, fn (array $a, array $b) => ($a[$anahtar] <=> $b[$anahtar]) * $yon);
        }

        return ['data' => array_slice($satirlar, ($liste['page'] - 1) * $liste['per_page'], $liste['per_page'])]
            + ListeIstegi::meta($liste['page'], $liste['per_page'], count($satirlar));
    }

    private static function normal(string $metin): string
    {
        return mb_strtolower(strtr($metin, ['İ' => 'i', 'I' => 'ı']));
    }
}
