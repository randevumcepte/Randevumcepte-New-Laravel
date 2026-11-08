<?php

namespace App\Domain\Tahsilat;

/**
 * Eski mobilin (ApiController) para hesabi — satir satir, saf. Web hesabi TahsilatDagitim'dedir; bu sinifa
 * yalniz mobil uclar girer. Kusurlar BILEREK korunur (kullanici karari: mobil tutarlar bugunkuyle birebir),
 * duzeltilmez. En onemlisi: vade dagitimindaki tlSayi veritabani degeri "1500.00" icin de calisir ve 150000
 * uretir; eski kod da boyle hesaplar.
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
     * tahsilat.tutar = indirimli toplam - komisyon (komisyon adisyona yansimaz).
     *
     * @return array{0:float,1:float} [adisyon odemesi, komisyon]
     */
    public static function odemeAyir(mixed $indirimliToplam, mixed $komisyon): array
    {
        $k = self::tlSayi($komisyon);

        return [max(0, self::tlSayi($indirimliToplam) - $k), $k];
    }

    /** musteri_indirimi = musteri% + harici gelir; kaleme yazilacak musteri payi harici disidir. */
    public static function musteriIndirimi(mixed $musteriIndirimi, mixed $hariciIndirim): float
    {
        return max(0, self::tlSayi($musteriIndirimi) - self::tlSayi($hariciIndirim));
    }

    /** Daha once indirim yazilmamis kalemin musteri indirimi payi (fiyata oranli, kalanla sinirli). */
    public static function musteriIndirimPayi(float $fiyat, float $toplamFiyat, float $indirim, float $onceki): float
    {
        return max(0, min(round(($fiyat / $toplamFiyat) * $indirim, 2), $fiyat - $onceki));
    }

    /** Kupon indirimi adisyona zaten yazildiysa harici indirimden bir kez dusulur. */
    public static function kuponDusulmus(float $hariciIndirim, float $kuponIndirim): float
    {
        return $kuponIndirim > 0 ? max(0, $hariciIndirim - $kuponIndirim) : $hariciIndirim;
    }

    /** Uygulanacak harici indirim: brut kalan borcla sinirli (bu odeme dusulmeden). */
    public static function uygulanacakIndirim(float $indirim, float $toplamFiyat, float $mevcutIndirim, float $oncekiTahsilat): float
    {
        return max(0, min($indirim, $toplamFiyat - $mevcutIndirim - $oncekiTahsilat));
    }

    /** Harici indirim ve kampanya kodu kalem payi. */
    public static function indirimPayi(float $fiyat, float $toplamFiyat, float $indirim): float
    {
        return round(($fiyat / $toplamFiyat) * $indirim, 2);
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

    /** Secili kalem payi (tahsilatekle): fiyata oranli, YUVARLANMAZ; kolon yuvarlar. Fiyat 0 ise stüdyo korumasi. */
    public static function kalemPayi(mixed $fiyat, mixed $toplamAdisyon, float $odeme): float
    {
        $oran = $toplamAdisyon > 0 ? $fiyat / $toplamAdisyon : 0;

        return $oran * $odeme;
    }

    /**
     * Taksit veya senet vadesi odemesinde plana bagli kalemin payi. fiyat ve plan toplami veritabani degeriyle,
     * odeme istekteki ham metinle verilir. Komisyon DUSULMEZ: eski kod ham indirimli toplami kullanir.
     */
    public static function vadeKalemPayi(mixed $fiyat, mixed $indirim, mixed $onceki, mixed $planToplami, mixed $odeme): float
    {
        return ((self::tlSayi($fiyat) - (float) $indirim - (float) $onceki) / self::tlSayi($planToplami)) * self::tlSayi($odeme);
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
     * Taksitli satista on odemenin kalem paylari. Fiyatlar istekteki ham metindir; cagiran tum kalem turlerini (hizmet,
     * urun, paket) tek listede verir, paylar odemeyi bir kez boler.
     *
     * @param  list<mixed>  $fiyatlar
     * @return list<float>
     */
    public static function onOdemePaylari(array $fiyatlar, float $kalemBasinaIndirim, mixed $odeme): array
    {
        $netler = array_map(fn ($f) => self::tlSayi($f) - $kalemBasinaIndirim, $fiyatlar);
        $toplam = array_sum($netler);
        $odemeSayi = self::tlSayi($odeme);

        return array_map(function ($net) use ($toplam, $odemeSayi) {
            if ($toplam == 0) {
                throw new \DivisionByZeroError('Division by zero');
            }

            return round(($net / $toplam) * $odemeSayi, 2);
        }, $netler);
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

        return $toplam > 0 ? min($tutar, $toplam) : 0;
    }

    /** Harici tahsilat kalem payi, yuvarlanmaz. */
    public static function hariciPay(float $fiyat, float $toplam, float $tahsilat): float
    {
        return ($fiyat / $toplam) * $tahsilat;
    }

    /** Harici kalem fiyati: negatif 0. */
    public static function hariciFiyat(mixed $ham): float
    {
        return max(0, self::virgulluSayi($ham));
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

    /** Hizli satis satiri: birim fiyat varsa o, yoksa katalog; miktar mutlak deger. */
    public static function hizliSatisSatiri(mixed $birimFiyat, mixed $katalogFiyat, mixed $miktar): array
    {
        $birim = $birimFiyat !== null ? (float) $birimFiyat : (float) $katalogFiyat;
        $adet = abs((float) ($miktar ?? 1));

        return ['birim' => $birim, 'adet' => $adet, 'tutar' => $birim * $adet];
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

    /** Seans sayisi: giris > 0 ise o, degilse paket hizmet seans toplami, o da 0 ise paket miktari. */
    public static function paketSeansSayisi(mixed $girilen, mixed $hizmetSeansToplami, mixed $paketMiktari): int
    {
        if ($girilen !== null && $girilen !== '' && (int) $girilen > 0) {
            return (int) $girilen;
        }

        return $hizmetSeansToplami == 0 ? (int) ($paketMiktari ?? 0) : (int) $hizmetSeansToplami;
    }

    /** Hizmet seans sayisi: bos veya 1 ve alti tekil (null), 2+ paket gibi. */
    public static function hizmetSeansSayisi(mixed $girilen): ?int
    {
        $s = trim((string) $girilen);

        return $s === '' || (int) $s <= 1 ? null : (int) $s;
    }

    /** Randevu tahsilatinda kapali adisyon kurali: toplam > 0 ve tahsilat toplamina esit veya fazla. */
    public static function tamamenOdenmisMi(float $toplam, float $tahsilat): bool
    {
        return $toplam > 0 && round($tahsilat, 2) >= round($toplam, 2);
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
