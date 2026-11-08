<?php

namespace App\Domain\Tahsilat;

use App\Domain\Paket\PaketTutari;

/**
 * Mobil uclarin para metni ve satir hesaplari — saf. Odemenin ve indirimin kalemlere DAGITIMI burada degil: web ile ayni
 * kural ve ayni kod (TahsilatDagitim, TahsilatYazServisi::kalemDagitimi / vadeDagitimi). Kullanici karari (2026-11-08):
 * eski mobilin dagitim hatalari (kurus farki, kalan borcu asan vade payi, "100.00" fiyatin 10000 okunmasi) duzeltilir;
 * mobil artik eski mobil gibi degil, web gibi yazar. Para metni kurusa paraKurus ile cevrilir.
 *
 * Eski sunucu PHP 7.4'tur. Karsilastirmalar bu yuzden (float) ile yazilir: "0,50" > 0 orada false'tur.
 */
final class MobilTahsilatHesabi
{
    /** str_replace(['.',','],['','.'], $deger) — eski kodun her yerde kullandigi cevrim. */
    public static function tlMetin(mixed $deger): string
    {
        return str_replace(['.', ','], ['', '.'], (string) ($deger ?? '0'));
    }

    public static function tlSayi(mixed $deger): float
    {
        return (float) self::tlMetin($deger);
    }

    /** Harici tahsilat ve telefonda satis cevrimi: yalniz virgul noktaya doner, nokta silinmez. */
    public static function virgulluSayi(mixed $deger): float
    {
        return (float) str_replace(',', '.', (string) ($deger ?? 0));
    }

    /** PHP 7.4 `$ham > 0`: sayisal olmayan metin bastaki sayiya iner ("1.234,50" → 1.234, "0,50" → 0). */
    public static function sifirdanBuyukMu(mixed $ham): bool
    {
        return (float) $ham > 0;
    }

    /** PHP 7.4 `$ham != 0`. */
    public static function sifirdanFarkliMi(mixed $ham): bool
    {
        return (float) $ham != 0;
    }

    /**
     * tahsilat.tutar = indirimli toplam - komisyon (komisyon adisyona yansimaz). Kurus aritmetigi.
     *
     * @return array{0:float,1:float} [adisyon odemesi, komisyon]
     */
    public static function odemeAyir(mixed $indirimliToplam, mixed $komisyon): array
    {
        $k = self::paraKurus($komisyon);

        return [round(max(0, self::paraKurus($indirimliToplam) - $k) / 100, 2), round($k / 100, 2)];
    }

    /** musteri_indirimi = musteri% + harici; kaleme yazilacak musteri payi harici disidir (kurus). */
    public static function musteriIndirimKurus(mixed $musteriIndirimi, mixed $hariciIndirim): int
    {
        return max(0, self::paraKurus($musteriIndirimi) - self::paraKurus($hariciIndirim));
    }

    /**
     * Musteri indirimi kalem paylari (kurus). Indirim secili tum plansiz kalemlerin fiyatina oranlidir; daha once indirim almis
     * kalemin payi baskasina aktarilmaz (cift uygulama olmasin). Uygun kalemlerin payi kendi kalaniyla (fiyat - onceki tahsilat)
     * sinirli; yuvarlama ve sinir artigi web dagitimiyla (TahsilatDagitim::oransal) kurus-kesin yerlesir.
     *
     * @param  array<int,int>  $fiyatlarKurus    tum secili plansiz kalemler
     * @param  array<int,bool>  $uygunlar        indirimi bos olan kalemler
     * @param  array<int,int>  $kapasitelerKurus fiyat - onceki tahsilat
     * @return array<int,int> kalem indeksi => pay (yalniz pay > 0)
     */
    public static function musteriIndirimPaylari(array $fiyatlarKurus, array $uygunlar, array $kapasitelerKurus, int $indirimKurus): array
    {
        $toplam = array_sum($fiyatlarKurus);
        $uygunFiyat = array_filter($fiyatlarKurus, fn ($i) => ! empty($uygunlar[$i]), ARRAY_FILTER_USE_KEY);
        if ($toplam <= 0 || $indirimKurus <= 0 || ! $uygunFiyat) {
            return [];
        }
        $hedef = (int) round(array_sum($uygunFiyat) / $toplam * $indirimKurus);

        return TahsilatDagitim::oransal($uygunFiyat, array_intersect_key($kapasitelerKurus, $uygunFiyat), $hedef);
    }



    /** Kampanya kodunun hedef indirimi: fiyat toplaminin yuzdesi. */
    public static function yuzdeIndirim(float $tutar, int $yuzde): float
    {
        return round($tutar * $yuzde / 100, 2);
    }

    /** Kampanya metninden yuzde: "%20" → [20, 'yuzde'], aksi halde [0, 'xalyode']. */
    public static function kampanyaIndirimi(string $metin): array
    {
        return preg_match('/%\s*(\d+)/u', $metin, $m) ? [(int) $m[1], 'yuzde'] : [0, 'xalyode'];
    }

    /** Taksit vade tutari: toplam / vade, iki hane. */
    public static function vadeTutari(mixed $toplam, int|string $vade): string
    {
        return number_format(self::tlSayi($toplam) / (int) $vade, 2, '.', '');
    }

    /**
     * Vade tarihleri: ilki verilen gun, sonrakiler bir oncekine +1 ay (strtotime; ay sonu tasmasi aynen).
     *
     * @return list<string>
     */
    public static function vadeTarihleri(string $baslangic, int|string $vade): array
    {
        $tarihler = [];
        $tarih = $baslangic;
        for ($i = 1; $i <= (int) $vade; $i++) {
            if ($i > 1) {
                $tarih = date('Y-m-d', strtotime('+1 month', strtotime($tarih)));
            }
            $tarihler[] = $tarih;
        }

        return $tarihler;
    }

    /** Seans tarihleri: ilki verilen gun, sonrakiler bir oncekine +aralik gun. */
    public static function seansTarihleri(?string $baslangic, int $seansSayisi, int|string $aralik): array
    {
        $tarihler = [];
        $tarih = $baslangic;
        for ($i = 1; $i <= $seansSayisi; $i++) {
            if ($i > 1) {
                $tarih = date('Y-m-d', strtotime('+'.$aralik.' days', strtotime((string) $tarih)));
            }
            $tarihler[] = $tarih;
        }

        return $tarihler;
    }

    /** Taksitte kalem basina esit indirim: (harici + musteri) / indirimsiz kalem sayisi. 0 kalemde hata verir, eski kod gibi. */
    public static function kalemBasinaIndirim(mixed $hariciIndirim, mixed $musteriIndirimi, int $kalemSayisi): float
    {
        if ($kalemSayisi === 0) {
            throw new \DivisionByZeroError('Division by zero');
        }

        return round((self::tlSayi($hariciIndirim) + self::tlSayi($musteriIndirimi)) / $kalemSayisi, 2);
    }

    /**
     * Taksitli satista on odemenin kalem paylari (TL). Web ile ayni kural, ayni kod (TahsilatDagitim::reconcile): tum kalemler
     * (hizmet, urun, paket) tek havuz, net fiyat oraninda, kurus artigi en buyuk kaleme; paylarin toplami odemeye birebir esit.
     * Eski mobil formulu her turu ayri oranliyor (odeme katlaniyordu), "100.00" fiyati 10000 okuyor ve kurus farki birakiyordu.
     *
     * @param  list<mixed>  $fiyatlar  istekteki fiyat ("100.00" ya da "1.234,56")
     * @return list<float>
     */
    public static function onOdemePaylari(array $fiyatlar, float $kalemBasinaIndirim, mixed $odeme): array
    {
        $indirim = (int) round($kalemBasinaIndirim * 100);
        $netler = array_map(fn ($f) => max(0, self::paraKurus($f) - $indirim), array_values($fiyatlar));

        return array_map(fn (int $k) => round($k / 100, 2), TahsilatDagitim::reconcile($netler, self::paraKurus($odeme)));
    }

    /**
     * Para metni → kurus. Virgul varsa Turkce bicim ("1.234,56"); virgulsuz ve noktalar uc haneli gruplarsa binlik ayirici
     * ("1.500" → 1500 TL); aksi halde ondalik nokta (veritabani "100.00").
     */
    public static function paraKurus(mixed $deger): int
    {
        $m = trim((string) ($deger ?? '0'));
        if (str_contains($m, ',')) {
            $m = str_replace(['.', ','], ['', '.'], $m);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $m)) {
            $m = str_replace('.', '', $m);
        }

        return (int) round((float) $m * 100);
    }

    /** Taksitte komisyondan arinmis odemenin dagitima giren metni (`number_format` + yeniden cevrim 2 haneye indirir). */
    public static function dagitimOdemesi(float $odeme): string
    {
        return number_format($odeme, 2, ',', '.');
    }

    /** Adisyon kaleminin kalan tutari: tum-alacaklar satirinda fiyat budur. */
    public static function kalemKalani(mixed $fiyat, mixed $tahsilEdilen, mixed $indirim): float
    {
        return (float) $fiyat - (float) $tahsilEdilen - (float) $indirim;
    }

    /** tum-alacaklar: kalan var ya da hediye, ve kalem taksit/senede baglanmamis. */
    public static function alacakGorunurMu(float $kalan, mixed $hediye, mixed $senetId, mixed $taksitId): bool
    {
        return ($kalan > 0 || $hediye) && $senetId === null && $taksitId === null;
    }

    /** Harici tahsilat tutari: negatif 0, kalem toplamiyla sinirli. Toplam 0 ise tahsilat yazilmaz (0 doner). */
    public static function hariciTahsilatTutari(mixed $ham, float $toplam): float
    {
        $tutar = max(0, self::virgulluSayi($ham));

        // Iki haneye yuvarlanir: tahsilat satiri ile kalemlere dagitilan kurus ayni sayidan cikar.
        return $toplam > 0 ? round(min($tutar, $toplam), 2) : 0;
    }

    /** Harici kalem fiyati: negatif 0, kolon gibi iki hane (toplam ve dagitim kurus-kesin kalsin). */
    public static function hariciFiyat(mixed $ham): float
    {
        return round(max(0, self::virgulluSayi($ham)), 2);
    }

    /** Harici urun adedi: 0 ve alti 1. */
    public static function hariciAdet(mixed $ham): float
    {
        $adet = self::virgulluSayi($ham ?? 1);

        return $adet > 0 ? $adet : 1;
    }

    /** Telefonda satis fiyati: iki haneye yuvarlanir. */
    public static function telefonFiyati(mixed $ham): float
    {
        return round(self::virgulluSayi($ham), 2);
    }

    /** Hizli satis satiri: birim fiyat varsa o, yoksa katalog; miktar mutlak deger; satir tutari kolon gibi iki hane. */
    public static function hizliSatisSatiri(mixed $birimFiyat, mixed $katalogFiyat, mixed $miktar): array
    {
        $birim = $birimFiyat !== null ? (float) $birimFiyat : (float) $katalogFiyat;
        $adet = abs((float) ($miktar ?? 1));

        return ['birim' => $birim, 'adet' => $adet, 'tutar' => round($birim * $adet, 2)];
    }

    /** Senet on odemesi var mi: str_replace sonucu `!= 0`. */
    public static function senetOnOdemesiVarMi(mixed $ham): bool
    {
        return self::sifirdanFarkliMi(self::tlMetin($ham));
    }

    /**
     * Ongorusmeden paket satis fiyati: giris gecerli sayiysa o, degilse paket hizmet toplami, o da 0 ise paket fiyati.
     */
    public static function paketSatisFiyati(mixed $hizmetToplami, mixed $paketFiyati, mixed $girilen): float
    {
        if ($girilen !== null && $girilen !== '' && is_numeric($girilen)) {
            return (float) $girilen;
        }

        return $hizmetToplami == 0 ? (float) ($paketFiyati ?? 0) : (float) $hizmetToplami;
    }

    /** Ongorusmeden urun satis satir fiyati: giris gecerli sayiysa satir toplami, degilse katalog x adet. */
    public static function urunSatisFiyati(mixed $katalogFiyat, mixed $adet, mixed $girilen): float
    {
        if ($girilen !== null && $girilen !== '' && is_numeric($girilen)) {
            return (float) $girilen;
        }

        return (float) $katalogFiyat * (float) $adet;
    }

    /** Ongorusmeden hizmet satis fiyati. */
    public static function hizmetSatisFiyati(mixed $katalogFiyat, mixed $girilen): mixed
    {
        return $girilen !== null && $girilen !== '' && is_numeric($girilen) ? (float) $girilen : ($katalogFiyat ?? 0);
    }

    /**
     * On gorusmeden paket satisinda onceden acilacak seans sayisi: giris > 0 ise o, degilse satis varsayilani
     * (PaketTutari::satisSeansi — bolge basina hak, web ile ayni). Sinirsiz pakette 0: seans adedi yoktur,
     * tavan satisa kopyalanir (PaketKullanimi). Eski kod hizmet seans TOPLAMINA, sinirsizda paket miktarina
     * (teknik tavan, ornek 366) dusup yuzlerce bos seans satiri aciyordu; duzeltildi.
     *
     * @param  array<int, array{seans?:mixed}>  $hizmetler
     */
    public static function paketSeansSayisi(mixed $girilen, array $hizmetler, mixed $paketMiktari, bool $sinirsiz = false): int
    {
        if ($sinirsiz) {
            return 0;
        }
        if ($girilen !== null && $girilen !== '' && (int) $girilen > 0) {
            return (int) $girilen;
        }

        return PaketTutari::satisSeansi($paketMiktari, $hizmetler);
    }

    /** Hizmet seans sayisi: bos veya 1 ve alti tekil (null), 2+ paket gibi. */
    public static function hizmetSeansSayisi(mixed $girilen): ?int
    {
        $s = trim((string) $girilen);

        return $s === '' || (int) $s <= 1 ? null : (int) $s;
    }

    /** Senet ve taksit satirinin kalem ozeti: "Sac Bakimi (H), Manikur (Ü), Full Bakim (P)". */
    public static function kalemOzeti(array $hizmetler, array $urunler, array $paketler): string
    {
        $parcalar = [];
        foreach ([[$hizmetler, ' (H)'], [$urunler, ' (Ü)'], [$paketler, ' (P)']] as [$adlar, $ek]) {
            foreach ($adlar as $ad) {
                if ($ad) {
                    $parcalar[] = $ad.$ek;
                }
            }
        }

        return implode(', ', $parcalar);
    }

    /** alacaklar-v2 kart basligi: adisyon kalem adlari (en fazla 3 + "+N"), yoksa aciklama, yoksa "Alacak". */
    public static function alacakBasligi(array $adlar, ?string $aciklama): string
    {
        $adlar = array_values(array_unique(array_filter($adlar)));
        if ($adlar) {
            $baslik = implode(', ', array_slice($adlar, 0, 3));

            return count($adlar) > 3 ? $baslik.' +'.(count($adlar) - 3) : $baslik;
        }

        return ! empty($aciklama) && $aciklama !== 'null' ? $aciklama : 'Alacak';
    }
}
