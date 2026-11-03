<?php

namespace App\Domain\Afis\Qr;

/**
 * Metin → QR matrisi (bayt modu, hata düzeltme M). Harici kütüphane yok; saf ve deterministik.
 *
 * Akış (ISO/IEC 18004 §7.4): mod göstergesi + uzunluk + veri → sonlandırıcı + bayt hizası +
 * dolgu (0xEC/0x11) → bloklara böl, Reed-Solomon EC ekle, araya ser → 8 maskeden en düşük
 * cezalısı. Sürüm ve maske seçimi ZXing / BaconQrCode ile aynı kuralla yapılır (eski
 * monolitin ürettiği afiş QR'ı ile modül modül aynı).
 *
 * Bayt modu ECI'siz yazılır (okuyucular ISO-8859-1 / UTF-8 varsayar); URL gibi ASCII
 * içerikte fark yoktur.
 */
final class QrKodlayici
{
    private const BAYT_MODU = 0b0100;

    public static function kodla(string $metin): QrMatris
    {
        $surum = self::surumSec(strlen($metin));
        $bitler = self::veriBitleri($metin, $surum);
        $sozcukler = self::arayaSer(self::baytlar($bitler), $surum);
        $seri = self::bitlereAc($sozcukler);

        $enIyi = null;
        $enIyiMaske = 0;
        $enDusuk = PHP_INT_MAX;
        for ($maske = 0; $maske < 8; $maske++) {
            $m = QrYerlesim::insaEt($seri, $surum, $maske);
            $puan = QrMaskeCezasi::puan($m);
            if ($puan < $enDusuk) {
                [$enDusuk, $enIyi, $enIyiMaske] = [$puan, $m, $maske];
            }
        }

        return new QrMatris($enIyi, $surum, $enIyiMaske);
    }

    /** En küçük sığan sürüm — ZXing'in iki geçişli tahminiyle aynı (önce sürüm 1 uzunluk alanı). */
    private static function surumSec(int $baytSayisi): int
    {
        $tahmin = self::sigan(4 + QrSurumTablosu::uzunlukBiti(1) + 8 * $baytSayisi);

        return self::sigan(4 + QrSurumTablosu::uzunlukBiti($tahmin) + 8 * $baytSayisi);
    }

    private static function sigan(int $bitSayisi): int
    {
        for ($s = 1; $s <= QrSurumTablosu::enBuyukSurum(); $s++) {
            if (QrSurumTablosu::veriSozcugu($s) >= intdiv($bitSayisi + 8, 8)) {
                return $s;
            }
        }
        throw new \LengthException('QR içeriği çok uzun ('.intdiv($bitSayisi, 8).' bayt)');
    }

    /** @return list<bool> sonlandırılmış + dolgulu veri bitleri (tam veri kapasitesi) */
    private static function veriBitleri(string $metin, int $surum): array
    {
        $bitler = [];
        $ekle = function (int $deger, int $genislik) use (&$bitler): void {
            for ($i = $genislik - 1; $i >= 0; $i--) {
                $bitler[] = (($deger >> $i) & 1) === 1;
            }
        };

        $ekle(self::BAYT_MODU, 4);
        $ekle(strlen($metin), QrSurumTablosu::uzunlukBiti($surum));
        foreach (unpack('C*', $metin) ?: [] as $bayt) {
            $ekle($bayt, 8);
        }

        $kapasite = QrSurumTablosu::veriSozcugu($surum) * 8;
        for ($i = 0; $i < 4 && count($bitler) < $kapasite; $i++) {
            $bitler[] = false;                   // sonlandırıcı
        }
        while (count($bitler) % 8 !== 0) {
            $bitler[] = false;                   // bayt hizası
        }
        for ($i = 0; count($bitler) < $kapasite; $i++) {
            $ekle($i % 2 === 0 ? 0xEC : 0x11, 8);
        }

        return $bitler;
    }

    /**
     * Veri sözcüklerini bloklara böler, her bloğa EC ekler ve sütun sütun araya serer.
     * Grup 2 blokları bir veri sözcüğü fazladır; EC uzunluğu tüm bloklarda aynı.
     *
     * @param  list<int>  $veri
     * @return list<int>
     */
    private static function arayaSer(array $veri, int $surum): array
    {
        $toplam = QrSurumTablosu::toplamSozcuk($surum);
        $blok = QrSurumTablosu::blokSayisi($surum);
        $grup2 = $toplam % $blok;
        $veriG1 = intdiv(count($veri), $blok);
        $ecSayisi = intdiv($toplam, $blok) - $veriG1;

        $veriBloklari = $ecBloklari = [];
        $konum = 0;
        for ($b = 0; $b < $blok; $b++) {
            $uzunluk = $veriG1 + ($b >= $blok - $grup2 ? 1 : 0);
            $parca = array_slice($veri, $konum, $uzunluk);
            $konum += $uzunluk;
            $veriBloklari[] = $parca;
            $ecBloklari[] = ReedSolomon::ecBaytlari($parca, $ecSayisi);
        }

        return array_merge(self::sutunSutun($veriBloklari), self::sutunSutun($ecBloklari));
    }

    /** @param  list<list<int>>  $bloklar */
    private static function sutunSutun(array $bloklar): array
    {
        $cikti = [];
        $enUzun = max(array_map('count', $bloklar));
        for ($i = 0; $i < $enUzun; $i++) {
            foreach ($bloklar as $b) {
                if (isset($b[$i])) {
                    $cikti[] = $b[$i];
                }
            }
        }

        return $cikti;
    }

    /** @param  list<bool>  $bitler */
    private static function baytlar(array $bitler): array
    {
        return array_map(
            fn (array $sekiz) => array_reduce($sekiz, fn (int $b, bool $bit) => ($b << 1) | (int) $bit, 0),
            array_chunk($bitler, 8),
        );
    }

    /** @param  list<int>  $baytlar */
    private static function bitlereAc(array $baytlar): array
    {
        $bitler = [];
        foreach ($baytlar as $b) {
            for ($i = 7; $i >= 0; $i--) {
                $bitler[] = (($b >> $i) & 1) === 1;
            }
        }

        return $bitler;
    }
}
