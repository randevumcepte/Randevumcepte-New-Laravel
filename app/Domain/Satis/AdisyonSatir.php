<?php

namespace App\Domain\Satis;

use App\Domain\Tahsilat\AdisyonHesap;

/**
 * Satis listesi TEK ADISYON satiri — SAF hesap (DB yok, test edilebilir).
 *
 * Eski API `ApiController@formatAdisyonFast` (ApiController.php:1670-1805) ile
 * BIREBIR parite: Flutter `adisyonpage` ve web "Satis Takibi" ayni sonucu gorur.
 *   - toplam (NET) = Σ(kalem.fiyat − indirim)          [hizmet+urun+paket]
 *   - odenen       = Σ kalem tahsilat (tahsilat_*.tutar)  [kalem-split]
 *   - kalan        = NET − odenen ; durum = kalan>0 ? acik : kapali
 *   - hakediş      = Σ(kalem tahsilat × prim_yuzde/100)   [tur bazli kova]
 *   - toplam (BRUT, hizmet/urun/paketToplam) = Σ fiyat (indirim dusulmez)
 *   - icerik/icerikKisaltilmis/hizmet_veren etiketleri eski ciktiyla ayni.
 * Seansli hizmet (randevu_id NULL & seans_sayisi>0): prim PAKET kovasina yazilir,
 * etiket "(P) (X Seans)". Hesaplar TAM SAYI KURUS uzerinden (yuvarlama guvenli).
 *
 * Girdi kalemleri (hepsi TL + onceden cozulmus prim_yuzde):
 *   hizmet/urun/paket: ['ad','fiyat','indirim','tahsilat','personel_id',
 *                       'personel_adi','prim_yuzde', (hizmet: 'seansli','seans_sayisi'),
 *                       (paket: 'seans_sayisi')]
 * Bu sinif hicbir sey YAZMAZ.
 */
class AdisyonSatir
{
    /**
     * @param array{hizmetler?:array,urunler?:array,paketler?:array} $kalemler
     * @return array<string,mixed>
     */
    public static function hesapla(array $kalemler): array
    {
        $hizmetler = $kalemler['hizmetler'] ?? [];
        $urunler = $kalemler['urunler'] ?? [];
        $paketler = $kalemler['paketler'] ?? [];

        $netKurus = 0;
        $odenenKurus = 0;
        $hizmetHakedisKurus = 0;
        $urunHakedisKurus = 0;
        $paketHakedisKurus = 0;
        $hizmetBrut = 0;
        $urunBrut = 0;
        $paketBrut = 0;

        $icerik = '';
        $icerikKisa = '';
        $personelStr = '';

        // ───────── Hizmetler ─────────
        foreach ($hizmetler as $h) {
            $fiyat = (float) ($h['fiyat'] ?? 0);
            $tahsilatKurus = AdisyonHesap::tlToKurus($h['tahsilat'] ?? 0);
            $netKurus += AdisyonHesap::tlToKurus($fiyat) - AdisyonHesap::tlToKurus($h['indirim'] ?? 0);
            $odenenKurus += $tahsilatKurus;
            $hizmetBrut += $fiyat;

            $seansli = ! empty($h['seansli']);
            $primKurus = (int) round($tahsilatKurus * ((float) ($h['prim_yuzde'] ?? 0)) / 100);
            if (! empty($h['personel_id'])) {
                // Seansli hizmetin primi PAKET kovasina (eski mantik: paket_prim_yuzde).
                if ($seansli) {
                    $paketHakedisKurus += $primKurus;
                } else {
                    $hizmetHakedisKurus += $primKurus;
                }
            }

            $adPer = (string) ($h['personel_adi'] ?? '');
            $etiket = $seansli ? ' (P) ('.(int) ($h['seans_sayisi'] ?? 0).' Seans)  ' : ' (H)  ';
            $icerik .= (string) ($h['ad'] ?? '').$etiket.$adPer.'  '.self::trFormat($fiyat)." ₺\r\n";
            $icerikKisa .= (string) ($h['ad'] ?? '').(! empty($h['personel_id']) ? ' ('.trim($adPer).')' : '').$etiket.$adPer.'  '.self::trFormat($fiyat)." ₺\r\n";
            $personelStr .= $adPer.' ';
        }

        // ───────── Urunler ─────────
        foreach ($urunler as $u) {
            $fiyat = (float) ($u['fiyat'] ?? 0);
            $tahsilatKurus = AdisyonHesap::tlToKurus($u['tahsilat'] ?? 0);
            $netKurus += AdisyonHesap::tlToKurus($fiyat) - AdisyonHesap::tlToKurus($u['indirim'] ?? 0);
            $odenenKurus += $tahsilatKurus;
            $urunBrut += $fiyat;

            if (! empty($u['personel_id'])) {
                $urunHakedisKurus += (int) round($tahsilatKurus * ((float) ($u['prim_yuzde'] ?? 0)) / 100);
            }

            $adPer = (string) ($u['personel_adi'] ?? '');
            $icerik .= (string) ($u['ad'] ?? '').' (Ü)  '.$adPer.'  '.self::trFormat($fiyat)." ₺\r\n";
            $icerikKisa .= (string) ($u['ad'] ?? '').(! empty($u['personel_id']) ? ' ('.trim($adPer).')' : '').' (Ü)  '.$adPer.'  '.self::trFormat($fiyat)." ₺\r\n";
            $personelStr .= $adPer.' ';
        }

        // ───────── Paketler ─────────
        foreach ($paketler as $p) {
            $fiyat = (float) ($p['fiyat'] ?? 0);
            $tahsilatKurus = AdisyonHesap::tlToKurus($p['tahsilat'] ?? 0);
            $netKurus += AdisyonHesap::tlToKurus($fiyat) - AdisyonHesap::tlToKurus($p['indirim'] ?? 0);
            $odenenKurus += $tahsilatKurus;
            $paketBrut += $fiyat;

            if (! empty($p['personel_id'])) {
                $paketHakedisKurus += (int) round($tahsilatKurus * ((float) ($p['prim_yuzde'] ?? 0)) / 100);
            }

            $adPer = (string) ($p['personel_adi'] ?? '');
            $seansAdet = (int) ($p['seans_sayisi'] ?? 0);
            $seansEt = $seansAdet > 0 ? '('.$seansAdet.' Seans) ' : '';
            $icerik .= (string) ($p['ad'] ?? '').' (P) '.$seansEt.' '.$adPer.'  '.self::trFormat($fiyat)." ₺\r\n";
            $icerikKisa .= (string) ($p['ad'] ?? '').(! empty($p['personel_id']) ? ' ('.trim($adPer).')' : '').' (P) '.$seansEt.' '.$adPer.'  '.self::trFormat($fiyat)." ₺\r\n";
            $personelStr .= $adPer.' ';
        }

        $kalanKurus = $netKurus - $odenenKurus;
        $hakedisKurus = $hizmetHakedisKurus + $urunHakedisKurus + $paketHakedisKurus;

        return [
            'toplam_kurus'          => $netKurus,
            'odenen_kurus'          => $odenenKurus,
            'kalan_kurus'           => $kalanKurus,

            'toplam'                => self::trFormat(AdisyonHesap::kurusToTl($netKurus)),
            'toplam_numeric'        => AdisyonHesap::kurusToTl($netKurus),
            'odenen'                => self::trFormat(AdisyonHesap::kurusToTl($odenenKurus)),
            'odenen_numeric'        => AdisyonHesap::kurusToTl($odenenKurus),
            'kalan_tutar'           => self::trFormat(AdisyonHesap::kurusToTl($kalanKurus)),
            'kalan_tutar_numeric'   => AdisyonHesap::kurusToTl($kalanKurus),

            // BRUT tutarlar (eski hizmet/urun/paketToplam — indirim DUSULMEZ).
            'hizmet_toplam'         => round($hizmetBrut, 2),
            'urun_toplam'           => round($urunBrut, 2),
            'paket_toplam'          => round($paketBrut, 2),

            'hizmet_hakedis'        => AdisyonHesap::kurusToTl($hizmetHakedisKurus),
            'urun_hakedis'          => AdisyonHesap::kurusToTl($urunHakedisKurus),
            'paket_hakedis'         => AdisyonHesap::kurusToTl($paketHakedisKurus),
            'hakedis'               => AdisyonHesap::kurusToTl($hakedisKurus),

            'icerik'                => $icerik,
            'icerik_kisaltilmis'    => $icerikKisa,
            'hizmet_veren'          => trim($personelStr),
            'durum'                 => $kalanKurus > 0 ? 'acik' : 'kapali',
        ];
    }

    /** Turkce para bicimi: 1.234,56 (eski number_format($n,2,',','.') ile ayni). */
    public static function trFormat($n): string
    {
        return number_format((float) $n, 2, ',', '.');
    }
}
