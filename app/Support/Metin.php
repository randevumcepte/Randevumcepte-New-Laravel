<?php

namespace App\Support;

/**
 * Metin yardimcilari — eski App\Helpers\Metin ile BIREBIR (ayni kayit iki
 * sistemden yazildiginda ayni bicimde dursun).
 */
final class Metin
{
    /** Her kelimenin ilk harfi Turkce kurala gore buyuk ("şampuan ılık" → "Şampuan Ilık"). */
    public static function basHarfBuyut(?string $metin): ?string
    {
        if ($metin === null || $metin === '') {
            return $metin;
        }

        return preg_replace_callback('/(^|\s)(\S)/u', fn ($m) => $m[1].match ($m[2]) {
            'i'     => 'İ',
            'ı'     => 'I',
            default => mb_strtoupper($m[2], 'UTF-8'),
        }, $metin);
    }
}
