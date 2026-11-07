<?php

namespace App\Services\BildirimKuyrugu\Kanal;

/**
 * Gonderim kanali telefon bicimi: eski WhatsAppService::normalizePhone ve KaraListeServisi::normalizeTel (iki kopya, ayni
 * kural). 90'li yalin rakam; 11 haneden kisa ise null. Saklama bicimi degildir (o TelefonNumarasi::kanonik).
 */
final class KanalTelefonu
{
    public static function normalize(mixed $ham): ?string
    {
        $n = (string) preg_replace('/\D+/', '', (string) $ham);
        if ($n === '') {
            return null;
        }
        if (str_starts_with($n, '00')) {
            $n = substr($n, 2);
        }
        if (strlen($n) === 10 && ($n[0] ?? '') === '5') {
            $n = '90'.$n;
        }
        if (strlen($n) === 11 && ($n[0] ?? '') === '0') {
            $n = '90'.substr($n, 1);
        }

        return strlen($n) >= 11 ? $n : null;
    }
}
