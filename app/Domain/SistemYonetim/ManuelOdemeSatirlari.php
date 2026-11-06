<?php

namespace App\Domain\SistemYonetim;

/** Manuel odeme linkinin serbest metin hizmet satirlari. Tutar Turkce bicimde gelir: 1.500,50 */
final class ManuelOdemeSatirlari
{
    private const TUTAR_BICIMI = '/^(\d{1,3}(\.\d{3})+|\d+)(,\d{1,2})?$/';

    /** Gecersiz ya da sifirsa null. "1500.50" gibi ondalik noktali metin kabul edilmez: 150050 TL'ye donusurdu. */
    public static function tutar(mixed $ham): ?float
    {
        if (is_int($ham) || is_float($ham)) {
            return $ham > 0 ? round((float) $ham, 2) : null;
        }
        if (! is_string($ham) || ! preg_match(self::TUTAR_BICIMI, trim($ham))) {
            return null;
        }
        $tutar = (float) str_replace(',', '.', str_replace('.', '', trim($ham)));

        return $tutar > 0 ? $tutar : null;
    }

    /**
     * Tamamen bos satirlar atlanir; yarim ya da bozuk satir "hatali" listesine girer ki link eksik kalemle uretilmesin.
     *
     * @return array{satirlar:list<array{ad:string,adet:int,tutar:float}>,hatali:list<int>} hatali: 1'den baslayan sira no
     */
    public static function ayristir(array $ham): array
    {
        $satirlar = [];
        $hatali = [];
        foreach (array_values($ham) as $i => $h) {
            $ad = trim((string) ($h['ad'] ?? ''));
            $hamTutar = $h['tutar'] ?? '';
            if ($ad === '' && is_string($hamTutar) && trim($hamTutar) === '') {
                continue;
            }
            $tutar = self::tutar($hamTutar);
            if ($ad === '' || $tutar === null) {
                $hatali[] = $i + 1;

                continue;
            }
            $satirlar[] = ['ad' => $ad, 'adet' => max(1, (int) ($h['adet'] ?? 1)), 'tutar' => $tutar];
        }

        return ['satirlar' => $satirlar, 'hatali' => $hatali];
    }

    /** @param list<array{adet:int,tutar:float}> $satirlar */
    public static function toplam(array $satirlar): float
    {
        return round(array_sum(array_map(fn (array $s) => $s['tutar'] * $s['adet'], $satirlar)), 2);
    }
}
