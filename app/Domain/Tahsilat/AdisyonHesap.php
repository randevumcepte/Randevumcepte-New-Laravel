<?php

namespace App\Domain\Tahsilat;

/**
 * Adisyon parasal ozeti — SAF hesap (DB yok, test edilebilir). Tum aritmetik
 * TAM SAYI KURUS uzerinden (float yuvarlama hatasi olmasin).
 *
 * KANONIK FORMUL — eski API `formatAdisyonFast` (ApiController.php:1670-1805)
 * ile birebir hizalanmistir (Flutter'in kullandigi yol):
 *   - NET    = Σ(kalem.fiyat − kalem.indirim_tutari)   [hizmet + urun + paket]
 *              * urun.fiyat ZATEN satir toplamidir (adet ile CARPILMAZ)
 *              * hediye kalemin fiyati zaten 0'dir (ayri bayrak aritmetikte yok)
 *              * adisyonlar.indirim_tutari (adisyon seviyesi) API yolunda KULLANILMAZ
 *   - ODENEN = Σ(tahsilatlar.tutar) WHERE para_girisi IN (0, NULL)  (komisyon haric)
 *   - KALAN  = NET − ODENEN   (negatif olabilir = fazla odeme)
 *   - DURUM  = kalan > 0 ? 'acik' : 'kapali'   (kalan <= 0 kapali)
 *
 * Bu sinif hicbir sey YAZMAZ.
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
     * @param array<array{fiyat:mixed,indirim:mixed}> $kalemler  Her kalem: fiyat + indirim (TL)
     * @param array<mixed> $tahsilatTutarlari  Odeme (para_girisi!=1) tutarlari (TL)
     * @return array{net_kurus:int,odenen_kurus:int,kalan_kurus:int,net:float,odenen:float,kalan:float,durum:string}
     */
    public static function ozet(array $kalemler, array $tahsilatTutarlari): array
    {
        $netKurus = 0;
        foreach ($kalemler as $k) {
            $netKurus += self::tlToKurus($k['fiyat'] ?? 0) - self::tlToKurus($k['indirim'] ?? 0);
        }

        $odenenKurus = 0;
        foreach ($tahsilatTutarlari as $t) {
            $odenenKurus += self::tlToKurus($t);
        }

        $kalanKurus = $netKurus - $odenenKurus;

        return [
            'net_kurus'    => $netKurus,
            'odenen_kurus' => $odenenKurus,
            'kalan_kurus'  => $kalanKurus,
            'net'          => self::kurusToTl($netKurus),
            'odenen'       => self::kurusToTl($odenenKurus),
            'kalan'        => self::kurusToTl($kalanKurus),
            'durum'        => $kalanKurus > 0 ? 'acik' : 'kapali',
        ];
    }
}
