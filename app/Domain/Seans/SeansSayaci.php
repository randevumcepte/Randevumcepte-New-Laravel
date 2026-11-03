<?php

namespace App\Domain\Seans;

/**
 * Seans sayaclari — SAF hesap (DB yok, unit-test edilir). Eski web seans takibi
 * (createDurumButonlari) ile ayni kural:
 *
 *   kullanilan    = geldi=1 sayisi
 *   kullanilmayan = geldi=0 sayisi
 *   telafi        = geldi=2 sayisi (telafi de kapasite tuketir)
 *   bekleyen      = toplam − kullanilan − kullanilmayan − telafi   (eksiye dusmez)
 *
 * Beklemedeki (geldi=null, ornegin randevuya bagli henuz isaretlenmemis) satirlar
 * bekleyen'den dusulmez — eskiyle ayni. Eski mobil uc bekleyen'i olmayan bir
 * alandan (bekleyen_seans) hesapladigi icin eksi donuyordu; v2'de web kurali kanonik.
 */
final class SeansSayaci
{
    /**
     * @param  iterable<mixed>  $geldiDegerleri  seanslarin geldi kolonlari
     * @return array{toplam:int,bekleyen:int,kullanilan:int,kullanilmayan:int,telafi:int}
     */
    public static function hesapla(int $toplam, iterable $geldiDegerleri): array
    {
        $say = [SeansDurumu::GELDI->value => 0, SeansDurumu::GELMEDI->value => 0, SeansDurumu::TELAFI->value => 0];
        foreach ($geldiDegerleri as $g) {
            $d = SeansDurumu::geldidenCoz($g)->value;
            if (isset($say[$d])) {
                $say[$d]++;
            }
        }
        $toplam = max(0, $toplam);
        $tuketilen = array_sum($say);

        return [
            'toplam'        => $toplam,
            'bekleyen'      => max(0, $toplam - $tuketilen),
            'kullanilan'    => $say[SeansDurumu::GELDI->value],
            'kullanilmayan' => $say[SeansDurumu::GELMEDI->value],
            'telafi'        => $say[SeansDurumu::TELAFI->value],
        ];
    }

    /** Henuz kaydi olmayan (bos daire) seans hakki: toplam − mevcut satir sayisi. */
    public static function bosHak(int $toplam, int $kayitSayisi): int
    {
        return max(0, $toplam - $kayitSayisi);
    }

    /**
     * Satir toplami: paket = seans_sayisi × paketteki hizmet sayisi (her hizmet/bolge
     * ayri seans hakki), tek hizmet = seans_sayisi.
     */
    public static function kalemToplami(int $seansSayisi, int $hizmetSayisi): int
    {
        return max(0, $seansSayisi) * max(0, $hizmetSayisi);
    }
}
