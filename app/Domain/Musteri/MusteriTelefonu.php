<?php

namespace App\Domain\Musteri;

/**
 * Musteri telefonu kanonik bicim + eski kayit varyantlari — eski
 * StoreAdminController::telefonKanonik / telefonAramaVaryantlari'ndan BIREBIR port.
 *
 * TURKIYE : +90 / 90 / 0 soyulur → 10 haneli "5XXXXXXXXX".
 * YABANCI : ulke kodu KORUNUR; yalnizca ayrac / gorunmez karakter / 00 oneki temizlenir.
 *
 * Duplicate-musteri kontrolu bu kanonik bicimle yapildigi icin iki sistemde AYNI
 * kalmalidir (personel telefonundaki eski normalize kuralindan farklidir; bkz.
 * App\Domain\Personel\Telefon).
 */
final class MusteriTelefonu
{
    /** "0532 123 45 67" / "+90 532..." / "0090532..." → "5321234567" */
    public static function kanonik(?string $ham): string
    {
        if ($ham === null) {
            return '';
        }
        // Kopyala-yapistir kaynakli gorunmez unicode (NBSP, ZWSP, LRM/RLM, BOM)
        $s = preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{200E}\x{200F}\x{FEFF}]/u', '', $ham);
        $d = preg_replace('/\D/', '', (string) $s);
        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }

        if (strlen($d) === 12 && str_starts_with($d, '90')) {
            return substr($d, 2);
        }
        if (strlen($d) === 11 && $d[0] === '0') {
            return substr($d, 1);
        }

        return $d; // 10 haneli TR cep zaten kanonik; yabanci numara oldugu gibi
    }

    /**
     * Kanonik numaranin DB'de gecmisten kalma farkli bicimleri (dedup aramasi icin).
     *
     * @return string[]
     */
    public static function aramaVaryantlari(string $kanonik): array
    {
        if ($kanonik === '') {
            return [];
        }
        $v = strlen($kanonik) === 10 && $kanonik[0] === '5'
            ? [$kanonik, '0'.$kanonik, '90'.$kanonik, '+90'.$kanonik, '0090'.$kanonik]
            : [$kanonik, '+'.$kanonik, '00'.$kanonik];

        return array_values(array_unique($v));
    }
}
