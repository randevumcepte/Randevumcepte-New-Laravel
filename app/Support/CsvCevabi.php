<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV indirme: UTF-8 BOM (Excel Turkce karakter) + ';' ayrac + formul korumasi. Satirlar iterable: buyuk liste bellege dolmaz. */
final class CsvCevabi
{
    /**
     * @param  list<string>  $basliklar
     * @param  iterable<array>  $satirlar
     */
    public static function indir(string $dosyaAdi, array $basliklar, iterable $satirlar): StreamedResponse
    {
        return response()->streamDownload(function () use ($basliklar, $satirlar) {
            $cikti = fopen('php://output', 'w');
            fwrite($cikti, "\xEF\xBB\xBF");
            fputcsv($cikti, $basliklar, ';', '"', '');
            foreach ($satirlar as $satir) {
                fputcsv($cikti, array_map(self::hucre(...), $satir), ';', '"', '');
            }
            fclose($cikti);
        }, $dosyaAdi, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** =,+,-,@ ile baslayan metin Excel'de formul olarak calisir; musteri adi gibi veriler disaridan gelebilir. */
    private static function hucre(mixed $deger): mixed
    {
        return is_string($deger) && preg_match('/^[=+\-@\t\r]/', $deger) ? "'".$deger : $deger;
    }
}
