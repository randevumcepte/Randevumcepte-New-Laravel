<?php

namespace App\Support;

/**
 * "gecerli_salonlar" biçimindeki şube grubu değerini (JSON dizi "[1,2]", virgüllü "1,2"
 * ya da dizi) pozitif, tekil int listesine çevirir — eski Personeller::salonIdListesiCoz
 * ile birebir. Çoklu şube ödül kaynakları (bildirim reklamı, çark, sadakat) ortak kullanır.
 */
final class SalonIdListesi
{
    /** @return list<int> */
    public static function coz(mixed $deger): array
    {
        if ($deger === null || $deger === '' || $deger === []) {
            return [];
        }
        if (is_string($deger)) {
            $json = json_decode($deger, true);
            $deger = is_array($json) ? $json : explode(',', $deger);
        }
        if (! is_array($deger)) {
            $deger = [$deger];
        }

        return array_values(array_unique(array_filter(array_map(fn ($v) => (int) trim((string) $v), $deger), fn ($n) => $n > 0)));
    }
}
