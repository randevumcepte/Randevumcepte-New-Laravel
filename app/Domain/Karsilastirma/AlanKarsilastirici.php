<?php

namespace App\Domain\Karsilastirma;

/**
 * Iki JSON govdesini listelenen alanlarda karsilastirir; sayi farki 1e-6'dan kucukse esittir (metin bicimi serialize_precision'a bagli).
 *
 * Yol bicimi: `a.b` alt alan, `a[].b` liste satirlari, `[].b` kok liste, `a[]` yalniz satir sayisi, `$` govdenin kendisi. Dizi donen
 * yol derin karsilastirilir. `['eski yol', 'yeni yol']` farkli adli alani eslestirir. Satirlar sirayla eslesir; `$eslestir` listesinde
 * (liste yolu => anahtar alanlar) olan liste anahtarla eslesir. Olmayan alan ile null ayni sayilir: ekran ikisini de null okur.
 */
final class AlanKarsilastirici
{
    private const TOLERANS = 0.000001;

    private const OZET_UZUNLUK = 200;

    /**
     * @param  array<int,string|array{0:string,1:string}>  $alanlar
     * @param  array<string,array<int,string>>  $eslestir
     * @return array<int,string> tekrarsiz fark satirlari; bos ise esit
     */
    public static function farklar(mixed $eski, mixed $yeni, array $alanlar, array $eslestir = []): array
    {
        $farklar = [];
        foreach ($alanlar as $alan) {
            if (is_array($alan)) {
                self::degerKarsilastir(self::yolDegeri($eski, $alan[0]), self::yolDegeri($yeni, $alan[1]), $alan[0].' = '.$alan[1], $farklar);

                continue;
            }
            self::dolas($eski, $yeni, $alan === '$' ? [] : explode('.', $alan), '', '', $eslestir, $alan, $farklar);
        }

        return array_values(array_unique($farklar));
    }

    public static function esitMi(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_array($a) || is_array($b)) {
            if (! is_array($a) || ! is_array($b)) {
                return false;
            }
            foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
                if (! self::esitMi($a[$k] ?? null, $b[$k] ?? null)) {
                    return false;
                }
            }

            return true;
        }
        if (is_bool($a) || is_bool($b)) {
            return $a === $b;
        }
        if (self::sayiMi($a) && self::sayiMi($b)) {
            return abs((float) $a - (float) $b) < self::TOLERANS;
        }

        return (string) $a === (string) $b;
    }

    /** Bastaki sifir kimlik veya telefon demektir: sayi sayilmaz. */
    private static function sayiMi(mixed $v): bool
    {
        return is_numeric($v) && ! (is_string($v) && preg_match('/^-?0\d/', $v));
    }

    private static function yolDegeri(mixed $govde, string $yol): mixed
    {
        if ($yol === '$' || $yol === '') {
            return $govde;
        }
        foreach (explode('.', $yol) as $parca) {
            if (! is_array($govde)) {
                return null;
            }
            $govde = $govde[$parca] ?? null;
        }

        return $govde;
    }

    /**
     * @param  array<int,string>  $parcalar
     * @param  array<string,array<int,string>>  $eslestir
     * @param  array<int,string>  $farklar
     */
    private static function dolas(mixed $eski, mixed $yeni, array $parcalar, string $etiket, string $listeYolu, array $eslestir, string $alan, array &$farklar): void
    {
        if ($parcalar === []) {
            self::degerKarsilastir($eski, $yeni, $etiket === '' ? $alan : $etiket, $farklar);

            return;
        }

        $parca = array_shift($parcalar);
        $listeMi = str_ends_with($parca, '[]');
        $ad = $listeMi ? substr($parca, 0, -2) : $parca;
        $eskiAlt = $ad === '' ? $eski : (is_array($eski) ? ($eski[$ad] ?? null) : null);
        $yeniAlt = $ad === '' ? $yeni : (is_array($yeni) ? ($yeni[$ad] ?? null) : null);
        $yeniEtiket = $etiket === '' ? $ad : ($ad === '' ? $etiket : $etiket.'.'.$ad);
        $yeniYol = $listeYolu === '' ? $ad : ($ad === '' ? $listeYolu : $listeYolu.'.'.$ad);

        if (! $listeMi) {
            self::dolas($eskiAlt, $yeniAlt, $parcalar, $yeniEtiket, $yeniYol, $eslestir, $alan, $farklar);

            return;
        }

        self::satirlariDolas($eskiAlt, $yeniAlt, $parcalar, $yeniEtiket, $yeniYol, $eslestir, $alan, $farklar);
    }

    /**
     * @param  array<int,string>  $parcalar
     * @param  array<string,array<int,string>>  $eslestir
     * @param  array<int,string>  $farklar
     */
    private static function satirlariDolas(mixed $eski, mixed $yeni, array $parcalar, string $etiket, string $yol, array $eslestir, string $alan, array &$farklar): void
    {
        $eskiListe = is_array($eski) ? $eski : [];
        $yeniListe = is_array($yeni) ? $yeni : [];
        if ($eski !== null && ! is_array($eski) || $yeni !== null && ! is_array($yeni)) {
            $farklar[] = $etiket.'[]: liste degil, eski '.self::ozet($eski).' yeni '.self::ozet($yeni);

            return;
        }
        if (count($eskiListe) !== count($yeniListe)) {
            $farklar[] = $etiket.'[]: satir sayisi eski '.count($eskiListe).' yeni '.count($yeniListe);
        }

        $anahtarlar = $eslestir[$yol] ?? null;
        $eskiSatir = $anahtarlar ? self::anahtarla($eskiListe, $anahtarlar) : array_values($eskiListe);
        $yeniSatir = $anahtarlar ? self::anahtarla($yeniListe, $anahtarlar) : array_values($yeniListe);

        if ($anahtarlar) {
            foreach (array_diff_key($eskiSatir, $yeniSatir) as $k => $_) {
                $farklar[] = $etiket."[$k]: yalniz eskide var";
            }
            foreach (array_diff_key($yeniSatir, $eskiSatir) as $k => $_) {
                $farklar[] = $etiket."[$k]: yalniz yenide var";
            }
        }
        if ($parcalar === []) {
            return;
        }
        foreach (array_intersect_key($eskiSatir, $yeniSatir) as $k => $satir) {
            self::dolas($satir, $yeniSatir[$k], $parcalar, $etiket."[$k]", $yol, $eslestir, $alan, $farklar);
        }
    }

    /**
     * @param  array<int|string,mixed>  $liste
     * @param  array<int,string>  $alanlar
     * @return array<string,mixed> ayni anahtar tekrarlanirsa siradaki satir `#2` eki alir
     */
    private static function anahtarla(array $liste, array $alanlar): array
    {
        $sonuc = [];
        $gorulen = [];
        foreach ($liste as $satir) {
            $parcalar = [];
            foreach ($alanlar as $alan) {
                $parcalar[] = is_array($satir) ? (string) self::normal($satir[$alan] ?? '') : '';
            }
            $k = implode('+', $parcalar);
            $gorulen[$k] = ($gorulen[$k] ?? 0) + 1;
            $sonuc[$gorulen[$k] > 1 ? $k.'#'.$gorulen[$k] : $k] = $satir;
        }

        return $sonuc;
    }

    private static function normal(mixed $v): mixed
    {
        return self::sayiMi($v) ? (float) $v : (is_scalar($v) ? $v : '');
    }

    /** @param  array<int,string>  $farklar */
    private static function degerKarsilastir(mixed $eski, mixed $yeni, string $etiket, array &$farklar): void
    {
        if (! self::esitMi($eski, $yeni)) {
            $farklar[] = "$etiket: eski ".self::ozet($eski).' yeni '.self::ozet($yeni);
        }
    }

    private static function ozet(mixed $v): string
    {
        $metin = (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return mb_strlen($metin) > self::OZET_UZUNLUK ? mb_substr($metin, 0, self::OZET_UZUNLUK).'...' : $metin;
    }
}
