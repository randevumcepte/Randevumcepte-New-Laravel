<?php

namespace App\Support;

/** Eski monolitle ortak JSON ayar dosyasi: okunamazsa bos dizi; yazarken mevcut anahtarlar korunur. */
final class JsonAyarDosyasi
{
    public static function oku(string $yol): array
    {
        $veri = is_file($yol) ? json_decode((string) file_get_contents($yol), true) : null;

        return is_array($veri) ? $veri : [];
    }

    public static function guncelle(string $yol, array $yeni): array
    {
        $veri = array_merge(self::oku($yol), $yeni);
        file_put_contents($yol, json_encode($veri, JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $veri;
    }
}
