<?php

namespace App\Domain\Paket;

/**
 * Paketin ETKIN fiyat / seans kurali — SAF, tek kaynak.
 *
 * Eski sistemde paket iki farkli bicimde yaziliyordu:
 *   - web (paket_ekle_guncelle): yalniz paket duzeyi (paketler.fiyat / miktar),
 *     paket_hizmetler.seans / fiyat bos
 *   - mobil v1: hizmet basina seans / fiyat + paket duzeyi (bossa hizmet toplami)
 * Okuyan her yer (paket listesi, katalog, adisyona ekleme, mobil paketgetir) ayni
 * kurali SQL/PHP'de ayri ayri tekrarliyordu: "hizmet toplami > 0 ise o, degilse paket
 * duzeyi". v2'de kural burada; yazarken de ayni kural uygulanir (paketler.fiyat/miktar
 * = etkin deger) — boylece eski panel ve v2 ayni paketi ayni fiyatla gorur.
 */
final class PaketTutari
{
    /**
     * @param  array<int, array{seans?:mixed, fiyat?:mixed}>  $hizmetler
     * @return array{fiyat: float, seans: int}
     */
    public static function etkin(mixed $paketFiyat, mixed $paketSeans, array $hizmetler): array
    {
        $hizmetFiyat = round(array_sum(array_map(fn ($h) => (float) ($h['fiyat'] ?? 0), $hizmetler)), 2);
        $hizmetSeans = (int) array_sum(array_map(fn ($h) => (int) ($h['seans'] ?? 0), $hizmetler));

        return [
            'fiyat' => $hizmetFiyat > 0 ? $hizmetFiyat : round((float) $paketFiyat, 2),
            'seans' => $hizmetSeans > 0 ? $hizmetSeans : (int) $paketSeans,
        ];
    }
}
