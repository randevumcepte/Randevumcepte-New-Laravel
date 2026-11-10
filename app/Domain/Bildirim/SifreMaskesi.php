<?php

namespace App\Domain\Bildirim;

/**
 * Mesaj kayitlarindaki acik sifreyi gizler: "şifreniz : AB12CD" → "şifreniz : *****". Yalniz kayitlara uygulanir, giden mesaj
 * degismez. Ayni metne ikinci kez uygulanirsa sonuc degismez.
 */
final class SifreMaskesi
{
    public const MASKE = '*****';

    /** Turkce I/İ/ı buyuk-kucuk eslesmesi /i ile olmadigi icin acik yazilir. */
    private const DESEN = '/([şŞsS][iİıI][fF][rR][eE](?:[nN][iİıI][zZ]|[sS][iİıI])?\s*[:：]\s*)([^\s.,;\\\\]+)/u';

    public static function uygula(?string $metin): ?string
    {
        return $metin === null ? null : (string) preg_replace(self::DESEN, '$1'.self::MASKE, $metin);
    }
}
