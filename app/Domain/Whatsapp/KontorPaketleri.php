<?php

namespace App\Domain\Whatsapp;

/**
 * WhatsApp kontör paket kataloğu — fiyat sunucuda otoriter (istemci paket KODU gönderir, tutar
 * göndermez). Eski StoreAdminController::whatsappPaketTalep + whatsapp.blade kartlarıyla AYNI
 * paketler; birim fiyat ve avantaj elle yazılmaz, adet + fiyattan hesaplanır.
 */
final class KontorPaketleri
{
    /** adet => fiyat (TL). İlk satır "liste" fiyatı: avantaj buna göre hesaplanır. */
    private const FIYATLAR = [
        10000  => 2850,
        20000  => 5300,
        40000  => 9800,
        60000  => 13800,
        80000  => 17000,
        100000 => 20000,
    ];

    private const KOD_ONEKI = 'kontor_';

    /** @return list<array{kod:string, ad:string, adet:int, fiyat:int, fiyat_metin:string, birim_fiyat:float, avantaj:int, indirim_yuzde:int, en_avantajli:bool}> */
    public static function liste(): array
    {
        $tabanAdet = array_key_first(self::FIYATLAR);
        $tabanBirim = self::FIYATLAR[$tabanAdet] / $tabanAdet;
        $sonAdet = array_key_last(self::FIYATLAR);
        $liste = [];
        foreach (self::FIYATLAR as $adet => $fiyat) {
            $liste[] = [
                'kod'           => self::KOD_ONEKI.$adet,
                'ad'            => self::sayi($adet).' Kontör',
                'adet'          => $adet,
                'fiyat'         => $fiyat,
                'fiyat_metin'   => self::sayi($fiyat).' TL',
                'birim_fiyat'   => round($fiyat / $adet, 3),
                'avantaj'       => (int) round($adet * $tabanBirim - $fiyat),
                'indirim_yuzde' => (int) round((1 - ($fiyat / $adet) / $tabanBirim) * 100),
                'en_avantajli'  => $adet === $sonAdet,
            ];
        }

        return $liste;
    }

    public static function bul(string $kod): ?array
    {
        foreach (self::liste() as $paket) {
            if ($paket['kod'] === $kod) {
                return $paket;
            }
        }

        return null;
    }

    /** @return list<string> doğrulama kuralı için */
    public static function kodlar(): array
    {
        return array_column(self::liste(), 'kod');
    }

    private static function sayi(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
