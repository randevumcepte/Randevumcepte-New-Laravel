<?php

namespace App\Domain\Personel;

/**
 * Telefon normalize + maske tespiti — eski ApiController@telefon_no_format_duzenle
 * ve telefonMaskeliMi'den BIREBIR port (cerceve/DB bagimsiz saf mantik).
 *
 * Normalize: bastaki +90 / 90 / 0 ve ( ) bosluk - karakterleri atilir.
 * Duplicate-telefon kontrolu bu kanonik bicimle yapildigi icin birebir korunmali.
 */
class Telefon
{
    /** "0532 (123) 45-67" / "+90..." → "5321234567" */
    public static function normalize(?string $telefon): string
    {
        if ($telefon === null) {
            return '';
        }
        $phone = preg_replace('/^(\+?90|0)/', '', $telefon);

        return str_replace(['(', ')', ' ', '-'], '', $phone);
    }

    /** Maskeli ("5068 *** ** 33") veya eksik haneli (<10) bir deger mi? (Yazmadan once kontrol.) */
    public static function maskeliMi(?string $tel): bool
    {
        if ($tel === null || $tel === '') {
            return false;
        }
        if (strpos($tel, '*') !== false) {
            return true;
        }

        return strlen(preg_replace('/\D/', '', $tel)) < 10;
    }
}
