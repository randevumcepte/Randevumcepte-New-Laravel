<?php

namespace App\Domain\Rapor;

/**
 * Mobil satis raporu (SAF) — eski ApiController hizmet/urun/paket/personelRaporlari gruplama ve formulu BIREBIR:
 * brut SUM(fiyat), tutar kalemin TUM tahsilati (donemden bagimsiz), TR bicimli metin + numeric, yuvarlama yok.
 * Web Satis Raporlari (SatisRaporServisi: net tutar, PrimHesap) bunu KULLANMAZ.
 *
 * $kazanc: tahsilat toplami kaynagi (servis SQL SUM ile verir). Kalem dizisi alanlari:
 * id, katalog_id, fiyat, ad, randevu_id, seans_sayisi, personel_id, personel(?array).
 */
final class MobilSatisRaporu
{
    /** Randevusuz ve seans_sayisi>0 hizmet kalemi paket sayilir. */
    public static function seansliMi(array $k): bool
    {
        return ($k['randevu_id'] ?? null) === null && (int) ($k['seans_sayisi'] ?? 0) > 0;
    }

    /**
     * Katalog kalemi basina bir satir, ilk gorulme sirasiyla.
     *
     * @param  array<int,array>  $kalemler
     * @param  callable(array<int,int>):mixed  $kazanc  kalem id listesi => tahsilat toplami
     * @return array<int,array>
     */
    public static function satirlar(array $kalemler, string $adAlani, string $idAlani, callable $kazanc, string $adEki = ''): array
    {
        $gruplar = [];
        foreach ($kalemler as $k) {
            $gruplar[$k['katalog_id'] ?? ''][] = $k;
        }

        $satirlar = [];
        foreach ($gruplar as $items) {
            $tutar = 0;
            foreach ($items as $i) {
                $tutar += $i['fiyat'];
            }
            $toplamKazanc = $kazanc(array_column($items, 'id'));
            $borc = $tutar - $toplamKazanc;
            $satirlar[] = [
                'id'                  => $items[0]['katalog_id'],
                'adet'                => count($items),
                'toplam_tutar'        => self::tr($tutar),
                $adAlani              => ($items[0]['ad'] ?? '').$adEki,
                'toplamKazanc'        => self::tr($toplamKazanc),
                'borc'                => self::tr($borc),
                'toplamTutarNumeric'  => $tutar,
                'toplamKazancNumeric' => $toplamKazanc,
                'borcNumeric'         => $borc,
                $idAlani              => $items[0]['katalog_id'],
            ];
        }

        return $satirlar;
    }

    /**
     * Personel raporu: yalniz aktif personel, duz yuzde. Seansli hizmet paket gelirine ve primine eklenir.
     * Yalniz urun satisi olan personelin adi null doner (eski cevapla ayni).
     *
     * @param  array<int,array>  $hizmetler
     * @param  array<int,array>  $urunler
     * @param  array<int,array>  $paketler
     * @param  callable(string,array<int,int>):mixed  $kazanc  tur, kalem id listesi => tahsilat toplami
     * @return array<int,array>
     */
    public static function personelRaporu(array $hizmetler, array $urunler, array $paketler, callable $kazanc): array
    {
        $aktif = fn (array $liste) => array_filter($liste, fn ($k) => ! empty($k['personel']) && $k['personel']['aktif'] == 1);
        $hizmetler = $aktif($hizmetler);

        $hizmet = [];
        foreach (self::personelGruplari(array_filter($hizmetler, fn ($k) => ! self::seansliMi($k))) as $pid => $items) {
            $p = $items[0]['personel'];
            $k = $kazanc('hizmet', array_column($items, 'id'));
            $hizmet[$pid] = ['personel_adi' => $p['personel_adi'] ?? null, 'gelir' => $k, 'prim' => $k * ($p['hizmet_prim_yuzde'] ?? 0) / 100];
        }

        $urun = [];
        foreach (self::personelGruplari($aktif($urunler)) as $pid => $items) {
            $p = $items[0]['personel'];
            $k = $kazanc('urun', array_column($items, 'id'));
            $urun[$pid] = ['gelir' => $k, 'prim' => $k * ($p['urun_prim_yuzde'] ?? 0) / 100];
        }

        $paket = [];
        foreach (self::personelGruplari($aktif($paketler)) as $pid => $items) {
            $p = $items[0]['personel'];
            $k = $kazanc('paket', array_column($items, 'id'));
            $paket[$pid] = ['personel_adi' => $p['personel_adi'] ?? null, 'gelir' => $k, 'prim' => $k * ($p['paket_prim_yuzde'] ?? 0) / 100];
        }
        foreach (self::personelGruplari(array_filter($hizmetler, fn ($k) => self::seansliMi($k))) as $pid => $items) {
            $p = $items[0]['personel'];
            $k = $kazanc('hizmet', array_column($items, 'id'));
            $paket[$pid] ??= ['personel_adi' => $p['personel_adi'] ?? null, 'gelir' => 0, 'prim' => 0];
            $paket[$pid]['gelir'] += $k;
            $paket[$pid]['prim'] += $k * ($p['paket_prim_yuzde'] ?? 0) / 100;
        }

        $rapor = [];
        foreach (array_unique(array_merge(array_keys($hizmet), array_keys($urun), array_keys($paket))) as $id) {
            $rapor[] = [
                'personel_adi'  => $hizmet[$id]['personel_adi'] ?? $urun[$id]['personel_adi'] ?? $paket[$id]['personel_adi'] ?? null,
                'hizmet_geliri' => $hizmet[$id]['gelir'] ?? 0,
                'hizmet_primi'  => $hizmet[$id]['prim'] ?? 0,
                'urun_geliri'   => $urun[$id]['gelir'] ?? 0,
                'urun_primi'    => $urun[$id]['prim'] ?? 0,
                'paket_geliri'  => $paket[$id]['gelir'] ?? 0,
                'paket_primi'   => $paket[$id]['prim'] ?? 0,
            ];
        }

        return $rapor;
    }

    /** @return array<int,array<int,array>> */
    private static function personelGruplari(array $kalemler): array
    {
        $gruplar = [];
        foreach ($kalemler as $k) {
            $gruplar[$k['personel_id']][] = $k;
        }

        return $gruplar;
    }

    private static function tr(mixed $tutar): string
    {
        return number_format((float) $tutar, 2, ',', '.');
    }
}
