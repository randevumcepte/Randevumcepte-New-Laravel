<?php

namespace App\Support;

use App\Support\Yetki\YetkiSabitleri;

/**
 * KVKK maskeleri — saf yardımcılar (tek kaynak). Telefon maskesi v2 standardı
 * (YetkiSabitleri::telefonMaskele); ad soyad maskesi eski Controller::adSoyadMaskele ile birebir:
 * ad tam, soyad yalnız ilk harf + nokta ("Ferdi Korkmaz" → "Ferdi K."); tek kelime olduğu gibi.
 */
final class KvkkMaske
{
    public static function adSoyad(?string $ad): string
    {
        $ad = trim((string) $ad);
        if ($ad === '') {
            return '';
        }
        $parca = preg_split('/\s+/u', $ad) ?: [$ad];
        if (count($parca) < 2) {
            return $ad;
        }
        $soyad = array_pop($parca);

        return implode(' ', $parca).' '.mb_strtoupper(mb_substr($soyad, 0, 1)).'.';
    }

    public static function telefon(?string $tel): string
    {
        return YetkiSabitleri::telefonMaskele($tel);
    }
}
