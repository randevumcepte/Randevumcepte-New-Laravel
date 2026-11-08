<?php

namespace App\Domain\Bildirim;

/** Tarih ve saati dogal Turkce soyler: "bugün saat 13:00", "yarın saat 13:00", "29 Nisan Çarşamba günü saat 13:00". */
final class DogalTarih
{
    private const GUNLER = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];

    private const AYLAR = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    /** @param  string|null  $bugun  Y-m-d; testte sabitlenir */
    public static function ifade(string $tarihSaat, ?string $bugun = null): string
    {
        $ts = strtotime($tarihSaat);
        $fark = (int) round((strtotime(date('Y-m-d', $ts)) - strtotime($bugun ?? date('Y-m-d'))) / 86400);
        $saat = date('H:i', $ts);

        return match ($fark) {
            0       => 'bugün saat '.$saat,
            1       => 'yarın saat '.$saat,
            default => (int) date('j', $ts).' '.self::AYLAR[(int) date('n', $ts)].' '.self::GUNLER[(int) date('w', $ts)].' günü saat '.$saat,
        };
    }
}
