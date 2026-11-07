<?php

namespace App\Domain\Satis;

/**
 * Mobil satis listesi TEK ADISYON satiri — SAF hesap (DB yok). Eski mobil ucu
 * `ApiController@formatAdisyonFast` ile satir satir ayni: kayan nokta toplam (kurusa cevrilmez),
 * yalniz kalan ROUND(,2), hakedis yuvarlanmaz, paket etiketinde seans/sinirsiz yazmaz (eski
 * sorgu `seans_sayisi` secmedigi icin hep bostu). Web satiri `AdisyonSatir`dir ve degismez.
 *
 * Girdi `SatisListeServisi::kalemleri` dizisi: ['hizmetler','urunler','paketler'] + her kalemde
 * ad, fiyat, indirim, tahsilat, personel_id, personel_adi, prim_yuzde (hizmet: seansli, seans_sayisi).
 */
final class MobilSatisSatiri
{
    /** @param array{hizmetler?:array,urunler?:array,paketler?:array} $kalemler */
    public static function hesapla(array $kalemler): array
    {
        $icerik = '';
        $icerikKisa = '';
        $personeller = '';
        $toplam = 0;
        $odenen = 0;
        $hakedis = ['hizmet' => 0, 'urun' => 0, 'paket' => 0];
        $brut = ['hizmet' => 0, 'urun' => 0, 'paket' => 0];

        $satirlar = [
            ['hizmet', $kalemler['hizmetler'] ?? []],
            ['urun', $kalemler['urunler'] ?? []],
            ['paket', $kalemler['paketler'] ?? []],
        ];
        foreach ($satirlar as [$tur, $liste]) {
            foreach ($liste as $k) {
                $fiyat = (float) ($k['fiyat'] ?? 0);
                $tahsilat = (float) ($k['tahsilat'] ?? 0);
                $toplam += $fiyat - (float) ($k['indirim'] ?? 0);
                $odenen += $tahsilat;
                $brut[$tur] += $fiyat;

                $seansli = $tur === 'hizmet' && ! empty($k['seansli']);
                if (! empty($k['personel_id'])) {
                    // Seansli hizmetin primi paket kovasina (duz paket_prim_yuzde).
                    $hakedis[$seansli ? 'paket' : $tur] += $tahsilat * ((float) ($k['prim_yuzde'] ?? 0) / 100);
                }

                $etiket = self::etiket($tur, $seansli, (int) ($k['seans_sayisi'] ?? 0));
                $ad = (string) ($k['ad'] ?? '');
                $personelAdi = (string) ($k['personel_adi'] ?? '');
                $kuyruk = $etiket.$personelAdi.'  '.AdisyonSatir::trFormat($fiyat)." ₺\r\n";
                $icerik .= $ad.$kuyruk;
                $icerikKisa .= $ad.(! empty($k['personel_id']) ? ' ('.trim($personelAdi).')' : '').$kuyruk;
                $personeller .= $personelAdi.' ';
            }
        }

        $kalan = round($toplam - $odenen, 2);

        return [
            'icerik'              => $icerik,
            'icerikKisaltilmis'   => $icerikKisa,
            'hizmet_veren'        => trim($personeller),
            'toplam'              => AdisyonSatir::trFormat($toplam),
            'toplam_numeric'      => $toplam,
            'odenen_numeric'      => $odenen,
            'kalan_tutar_numeric' => $kalan,
            'odenen'              => AdisyonSatir::trFormat($odenen),
            'kalan_tutar'         => AdisyonSatir::trFormat($kalan),
            'hakedisTutar'        => $hakedis['hizmet'] + $hakedis['urun'] + $hakedis['paket'],
            'hizmetHakedisTutar'  => $hakedis['hizmet'],
            'urunHakedisTutar'    => $hakedis['urun'],
            'paketHakedisTutar'   => $hakedis['paket'],
            'hizmetToplam'        => $brut['hizmet'],
            'urunToplam'          => $brut['urun'],
            'paketToplam'         => $brut['paket'],
            'durum'               => $kalan > 0 ? 'acik' : 'kapali',
        ];
    }

    private static function etiket(string $tur, bool $seansli, int $seansSayisi): string
    {
        return match ($tur) {
            'hizmet' => $seansli ? ' (P) ('.$seansSayisi.' Seans)  ' : ' (H)  ',
            'urun'   => ' (Ü)  ',
            default  => ' (P)  ',
        };
    }
}
