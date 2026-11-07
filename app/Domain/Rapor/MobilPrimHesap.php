<?php

namespace App\Domain\Rapor;

/**
 * Mobil prim hesabi (SAF) — eski ApiController formatAdisyonFast + primHakedisTopluApi + personelPrimHesaplaAyYil
 * BIREBIR. Web Prim & Hak Edis formulu Domain/Rapor/PrimHesap'tir; ikisi farklidir, birbirinin yerine gecmez:
 * burada toplam brut fiyat, hakedis kalem kalem tahsilat * oran, ara yuvarlama yok.
 *
 * Kalem dizisi: id, katalog_id, fiyat, indirim_tutari, tahsilat, personel_id, personel(?array), ad,
 * randevu_id, seans_sayisi. Adisyon dizisi: id, tarih, created_at, musteri, hizmetler, urunler, paketler.
 */
final class MobilPrimHesap
{
    /** @return array{donem:string, baslangic:string, bitis:string, gun:?string, ay:string, yil:string} */
    public static function aralik(mixed $ay, mixed $yil, mixed $gun, string $simdi): array
    {
        $bugun = substr($simdi, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $gun)) {
            return [
                'donem'     => substr((string) $gun, 0, 7),
                'baslangic' => $gun.' 00:00:00',
                'bitis'     => $gun === $bugun ? $simdi : $gun.' 23:59:59',
                'gun'       => (string) $gun,
                'ay'        => substr((string) $gun, 5, 2),
                'yil'       => substr((string) $gun, 0, 4),
            ];
        }

        $ay = $ay ? str_pad((string) $ay, 2, '0', STR_PAD_LEFT) : substr($simdi, 5, 2);
        $yil = $yil ? (string) $yil : substr($simdi, 0, 4);
        $donem = $yil.'-'.$ay;
        $baslangic = $donem.'-01 00:00:00';
        // Bu ay ise simdiki ana kadar sayilir.
        $bitis = $donem === substr($bugun, 0, 7) ? $simdi : $donem.'-'.date('t', strtotime($baslangic)).' 23:59:59';

        return ['donem' => $donem, 'baslangic' => $baslangic, 'bitis' => $bitis, 'gun' => null, 'ay' => $ay, 'yil' => $yil];
    }

    /** {tur}_prim_detayli ise kalem orani (yoksa 0), degilse personelin genel yuzdesi. */
    public static function oran(?array $personel, string $tur, mixed $kalemId, array $harita): float
    {
        if (! $personel) {
            return 0.0;
        }
        if (! empty($personel[$tur.'_prim_detayli'])) {
            return (float) ($harita[$personel['id']][$tur][$kalemId] ?? 0);
        }

        return (float) ($personel[$tur.'_prim_yuzde'] ?? 0);
    }

    /** Adisyon karti: tutarlar net (fiyat - indirim), hakedis tahsilat uzerinden, xxxToplam brut. */
    public static function adisyon(array $a, array $harita): array
    {
        $toplam = 0;
        $odenen = 0;
        $hizmetHakedis = 0;
        $urunHakedis = 0;
        $paketHakedis = 0;
        $icerik = '';
        $kisa = '';
        $personeller = '';

        foreach ($a['hizmetler'] as $k) {
            $toplam += ($k['fiyat'] ?? 0) - ($k['indirim_tutari'] ?? 0);
            $odenen += $k['tahsilat'];
            $seansli = MobilSatisRaporu::seansliMi($k);
            if ($k['personel_id'] && $k['personel']) {
                if ($seansli) {
                    $paketHakedis += $k['tahsilat'] * ((float) ($k['personel']['paket_prim_yuzde'] ?? 0) / 100);
                } else {
                    $hizmetHakedis += $k['tahsilat'] * (self::oran($k['personel'], 'hizmet', $k['katalog_id'], $harita) / 100);
                }
            }
            $etiket = $seansli ? ' (P) ('.(int) $k['seans_sayisi'].' Seans)  ' : ' (H)  ';
            self::metinEkle($k, $etiket, $icerik, $kisa, $personeller);
        }
        foreach ($a['urunler'] as $k) {
            $toplam += ($k['fiyat'] ?? 0) - ($k['indirim_tutari'] ?? 0);
            $odenen += $k['tahsilat'];
            if ($k['personel_id'] && $k['personel']) {
                $urunHakedis += $k['tahsilat'] * (self::oran($k['personel'], 'urun', $k['katalog_id'], $harita) / 100);
            }
            self::metinEkle($k, ' (Ü)  ', $icerik, $kisa, $personeller);
        }
        foreach ($a['paketler'] as $k) {
            $toplam += ($k['fiyat'] ?? 0) - ($k['indirim_tutari'] ?? 0);
            $odenen += $k['tahsilat'];
            if ($k['personel_id'] && $k['personel']) {
                $paketHakedis += $k['tahsilat'] * (self::oran($k['personel'], 'paket', $k['katalog_id'], $harita) / 100);
            }
            // Eski sorgu paket kaleminin seans_sayisi kolonunu okumuyordu; seans eki hic yazilmaz.
            self::metinEkle($k, ' (P)  ', $icerik, $kisa, $personeller);
        }

        $kalan = round($toplam - $odenen, 2);

        return [
            'id'                 => $a['id'],
            'acilis_tarihi'      => date('d.m.Y', strtotime((string) $a['tarih'])),
            'acilis_saati'       => $a['created_at'] ? date('H:i', strtotime((string) $a['created_at'])) : '',
            'musteri'            => $a['musteri'] ?? '-',
            'icerik'             => $icerik,
            'icerikKisaltilmis'  => $kisa,
            'hizmet_veren'       => trim($personeller),
            'toplam'             => self::tr($toplam),
            'toplam_numeric'     => $toplam,
            'odenen_numeric'     => $odenen,
            'kalan_tutar_numeric' => $kalan,
            'odenen'             => self::tr($odenen),
            'kalan_tutar'        => self::tr($kalan),
            'hakedisTutar'       => $hizmetHakedis + $urunHakedis + $paketHakedis,
            'hizmetHakedisTutar' => $hizmetHakedis,
            'urunHakedisTutar'   => $urunHakedis,
            'paketHakedisTutar'  => $paketHakedis,
            'hizmetToplam'       => self::fiyatToplami($a['hizmetler']),
            'urunToplam'         => self::fiyatToplami($a['urunler']),
            'paketToplam'        => self::fiyatToplami($a['paketler']),
        ];
    }

    /** Adisyonun yalniz personelin kalemleri; kalemi yoksa null (eski whereHas + eager where). */
    public static function personelKapsami(array $a, int $personelId): ?array
    {
        $suz = fn (array $l) => array_values(array_filter($l, fn ($k) => (int) $k['personel_id'] === $personelId));
        $a['hizmetler'] = $suz($a['hizmetler']);
        $a['urunler'] = $suz($a['urunler']);
        $a['paketler'] = $suz($a['paketler']);

        return $a['hizmetler'] || $a['urunler'] || $a['paketler'] ? $a : null;
    }

    /** @param  array<int,array>  $kartlar  adisyon() ciktilari, sorgu sirasiyla @return array<string,float|int> */
    public static function toplamlar(array $kartlar): array
    {
        $alanlar = ['hizmet_toplam' => 'hizmetToplam', 'urun_toplam' => 'urunToplam', 'paket_toplam' => 'paketToplam',
            'hizmet_hakedis' => 'hizmetHakedisTutar', 'urun_hakedis' => 'urunHakedisTutar', 'paket_hakedis' => 'paketHakedisTutar'];
        $sonuc = [];
        foreach ($alanlar as $hedef => $kaynak) {
            $t = 0;
            foreach ($kartlar as $k) {
                $t += (float) ($k[$kaynak] ?? 0);
            }
            $sonuc[$hedef] = $t;
        }

        return $sonuc;
    }

    /** Personel detay cevabi: metin (TR) + numeric toplamlar, adisyonlar. */
    public static function aylikCevap(array $kartlar, string $ay, string $yil): array
    {
        $t = self::toplamlar($kartlar);
        $cevap = [];
        foreach ($t as $alan => $deger) {
            $cevap[$alan] = self::tr($deger);
            $cevap[$alan.'_numeric'] = $deger;
        }

        return $cevap + ['adisyonlar' => array_values($kartlar), 'donem' => $ay.'.'.$yil];
    }

    /**
     * Toplu hak edis satiri. Net: maas + hakedisler + bonus - kesinti - personel gideri + telafi devri (isaretli).
     *
     * @param  array  $p  personel satiri
     * @param  array  $t  toplamlar() ciktisi
     * @param  array{maas:float, prim:float, diger:float, bonus:float, kesinti:float, telafi:float, masraf:float}  $h
     * @param  ?float  $gunOdenen  gunluk modda o gun odenen prim; aylik modda null
     */
    public static function hakedisSatiri(array $p, array $t, array $h, ?float $gunOdenen = null): array
    {
        $net = (float) $p['maas'] + $t['hizmet_hakedis'] + $t['urun_hakedis'] + $t['paket_hakedis']
            + $h['bonus'] - $h['kesinti'] - $h['masraf'] + $h['telafi'];

        $satir = [
            'personel_id'       => (string) $p['id'],
            'personel_adi'      => (string) $p['personel_adi'],
            'unvan'             => (string) ($p['unvan'] ?? ''),
            'cep_telefon'       => (string) ($p['cep_telefon'] ?? ''),
            'profil_resmi'      => (string) ($p['profil_resmi'] ?? ''),
            'cinsiyet'          => (string) ($p['cinsiyet'] ?? ''),
            'maas'              => (float) $p['maas'],
            'hizmet_prim_yuzde' => (float) $p['hizmet_prim_yuzde'],
            'urun_prim_yuzde'   => (float) $p['urun_prim_yuzde'],
            'paket_prim_yuzde'  => (float) $p['paket_prim_yuzde'],
            'aktif'             => (string) $p['aktif'],
            'durum'             => (string) $p['aktif'],
            'takvimde_gorunsun' => (string) $p['takvimde_gorunsun'],
            'takvim_sirasi'     => (string) ($p['takvim_sirasi'] ?? ''),
            'renk'              => (string) ($p['renk'] ?? ''),
            'hesap_turu'        => (string) ($p['hesap_turu'] ?? ''),
            'salon_id'          => (string) $p['salon_id'],
            'dahili_no'         => (string) ($p['dahili_no'] ?? ''),
            'hizmet_toplam'     => $t['hizmet_toplam'],
            'urun_toplam'       => $t['urun_toplam'],
            'paket_toplam'      => $t['paket_toplam'],
            'hizmet_hakedis'    => $t['hizmet_hakedis'],
            'urun_hakedis'      => $t['urun_hakedis'],
            'paket_hakedis'     => $t['paket_hakedis'],
            'odenen_maas'       => $h['maas'],
            'odenen_prim'       => $h['prim'],
            'odenen_diger'      => $h['diger'],
            'bonus'             => $h['bonus'],
            'kesinti'           => $h['kesinti'],
            'telafi_devri'      => $h['telafi'],
            'masraf'            => $h['masraf'],
            'borclu'            => $net < 0,
        ];

        if ($gunOdenen !== null) {
            $prim = $t['hizmet_hakedis'] + $t['urun_hakedis'] + $t['paket_hakedis'];
            $satir['gun_prim'] = $prim;
            $satir['gun_odenen'] = $gunOdenen;
            $satir['gun_kalan'] = max(0, $prim - $gunOdenen);
            $satir['gun_durum'] = self::gunDurumu($prim, $gunOdenen);
        }

        return $satir;
    }

    public static function gunDurumu(float|int $prim, float|int $odenen): string
    {
        if ($prim <= 0 || $odenen <= 0) {
            return 'bekliyor';
        }
        if ($odenen < $prim) {
            return 'kismi';
        }

        return $odenen == $prim ? 'tam' : 'fazla';
    }

    /** personel.maas_tutar_gor yoksa: maas, prim orani, hakedis ve odeme tutarlari sunucudan cikmaz. */
    public static function satirMaskele(array $s): array
    {
        foreach (['maas', 'hizmet_prim_yuzde', 'urun_prim_yuzde', 'paket_prim_yuzde', 'hizmet_hakedis', 'urun_hakedis', 'paket_hakedis',
            'odenen_maas', 'odenen_prim', 'odenen_diger', 'bonus', 'kesinti', 'telafi_devri', 'masraf', 'gun_prim', 'gun_odenen', 'gun_kalan'] as $alan) {
            if (array_key_exists($alan, $s)) {
                $s[$alan] = null;
            }
        }
        $s['borclu'] = false;

        return $s;
    }

    public static function aylikMaskele(array $c): array
    {
        foreach (['hizmet_hakedis', 'urun_hakedis', 'paket_hakedis'] as $alan) {
            $c[$alan] = null;
            $c[$alan.'_numeric'] = null;
        }
        $c['adisyonlar'] = array_map(fn ($a) => ['hakedisTutar' => null, 'hizmetHakedisTutar' => null, 'urunHakedisTutar' => null, 'paketHakedisTutar' => null] + $a, $c['adisyonlar']);

        return $c;
    }

    private static function metinEkle(array $k, string $etiket, string &$icerik, string &$kisa, string &$personeller): void
    {
        $ad = $k['ad'] ?? '';
        $personel = $k['personel']['personel_adi'] ?? '';
        $fiyat = self::tr($k['fiyat'] ?? 0)." ₺\r\n";
        $icerik .= $ad.$etiket.$personel.'  '.$fiyat;
        $kisa .= $ad.($k['personel_id'] ? ' ('.trim($personel).')' : '').$etiket.$personel.'  '.$fiyat;
        $personeller .= $personel.' ';
    }

    private static function fiyatToplami(array $kalemler): float|int
    {
        $t = 0;
        foreach ($kalemler as $k) {
            $t += $k['fiyat'];
        }

        return $t;
    }

    private static function tr(mixed $tutar): string
    {
        return number_format((float) $tutar, 2, ',', '.');
    }
}
