<?php

namespace App\Domain\Personel;

use App\Domain\Telefon\TelefonNumarasi;

/**
 * Telefon normalize + maske tespiti — eski ApiController@telefon_no_format_duzenle
 * ve telefonMaskeliMi'den BIREBIR port (cerceve/DB bagimsiz saf mantik).
 *
 * Normalize: TelefonNumarasi::kanonik ile ayni (tek kural): TR → 10 hane "5XXXXXXXXX", yabanci → "+KOD...".
 * Duplicate-telefon kontrolu iki tarafi da bu fonksiyondan gecirdigi icin tutarli kalir.
 */
class Telefon
{
    /** "0532 (123) 45-67" / "+90..." → "5321234567" */
    /**
     * Tek kural: TelefonNumarasi::kanonik (TR → 10 hane, yabancı → "+KOD..."). Eskiden ayrı bir düzenli ifadeydi; "0049…"
     * yabancı numarayı "049…" yapıyor, görünmez boşluk ve noktayı temizlemiyordu.
     */
    public static function normalize(?string $telefon): string
    {
        return TelefonNumarasi::kanonik($telefon);
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
