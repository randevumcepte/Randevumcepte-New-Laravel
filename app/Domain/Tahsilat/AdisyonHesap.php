<?php

namespace App\Domain\Tahsilat;

/**
 * Adisyon parasal ozeti — SAF hesap (DB yok, test edilebilir). Tum aritmetik
 * TAM SAYI KURUS uzerinden yapilir (float yuvarlama hatasi olmasin).
 *
 * !!! DIKKAT — net/kalan formulu GECICI (shadow ile dogrulanacak) !!!
 * Varsayimlar (eski monolitle karsilastirilip kesinlesene kadar GUVENILMEZ):
 *   - kalem net = (hediye ? 0 : fiyat * adet) - indirim    (adet: urun icin >1, digerleri 1)
 *   - net < 0 olmaz (max 0)
 *   - odenen = SUM(tahsilatlar.tutar)   ← bu KESIN/iyi tanimli
 *   - kalan = net - odenen
 * odenen degeri guvenilirdir; net/kalan Faz 1 shadow karsilastirmasindan sonra
 * sabitlenecektir. Bu sinif hicbir sey YAZMAZ.
 */
class AdisyonHesap
{
    public static function tlToKurus($tl): int
    {
        return (int) round(((float) $tl) * 100);
    }

    public static function kurusToTl(int $kurus): float
    {
        return round($kurus / 100, 2);
    }

    /**
     * @param array<array{fiyat:mixed,indirim:mixed,adet:mixed,hediye:bool}> $kalemler
     * @param array<mixed> $tahsilatTutarlari  Her tahsilatin tutari (TL)
     * @return array{net_kurus:int,odenen_kurus:int,kalan_kurus:int,net:float,odenen:float,kalan:float}
     */
    public static function ozet(array $kalemler, array $tahsilatTutarlari): array
    {
        $netKurus = 0;
        foreach ($kalemler as $k) {
            $adet = (isset($k['adet']) && (float) $k['adet'] > 0) ? (float) $k['adet'] : 1.0;
            $brutKurus = ! empty($k['hediye'])
                ? 0
                : (int) round(((float) ($k['fiyat'] ?? 0)) * $adet * 100);
            $indirimKurus = (int) round(((float) ($k['indirim'] ?? 0)) * 100);
            $netKurus += max(0, $brutKurus - $indirimKurus);
        }

        $odenenKurus = 0;
        foreach ($tahsilatTutarlari as $t) {
            $odenenKurus += (int) round(((float) $t) * 100);
        }

        $kalanKurus = $netKurus - $odenenKurus;

        return [
            'net_kurus'    => $netKurus,
            'odenen_kurus' => $odenenKurus,
            'kalan_kurus'  => $kalanKurus,
            'net'          => self::kurusToTl($netKurus),
            'odenen'       => self::kurusToTl($odenenKurus),
            'kalan'        => self::kurusToTl($kalanKurus),
        ];
    }
}
