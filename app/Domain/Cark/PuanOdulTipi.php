<?php

namespace App\Domain\Cark;

/**
 * Puan ödülü merdiveni basamağının tipi (salon_puan_odulleri.tip). Mobil v1 dört tipi kabul
 * ediyordu, eski web formu paket indirimini göstermiyordu — v2'de tek liste (mobil ile aynı).
 * Hediye değer taşımaz (müşteri tarafında %100 sayılır).
 */
final class PuanOdulTipi
{
    public const HEDIYE = 'hediye';

    /** kod => [etiket, kısa ad, rozet rengi] */
    private const TANIM = [
        DilimTipi::HIZMET => ['Hizmet İndirimi', 'Hizmet İnd.', 'mor'],
        DilimTipi::URUN   => ['Ürün İndirimi', 'Ürün İnd.', 'mavi'],
        DilimTipi::PAKET  => ['Paket İndirimi', 'Paket İnd.', 'turuncu'],
        self::HEDIYE      => ['Hediye', 'Hediye', 'yesil'],
    ];

    /** @return string[] */
    public static function kodlar(): array
    {
        return array_keys(self::TANIM);
    }

    public static function degerliMi(string $tip): bool
    {
        return $tip !== self::HEDIYE;
    }

    public static function sozluk(): array
    {
        return array_map(fn ($kod) => [
            'kod' => $kod, 'ad' => self::TANIM[$kod][0], 'kisa' => self::TANIM[$kod][1],
            'renk' => self::TANIM[$kod][2], 'degerli' => self::degerliMi($kod),
        ], self::kodlar());
    }
}
