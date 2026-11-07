<?php

namespace App\Domain\Randevu;

use InvalidArgumentException;

/**
 * Saat kapama (takvim blogu) SAF kurallari — DB yok, unit-test edilir.
 * Eski ApiController@saatkapamaekle ile ayni tekrar mantigi; fark: tekrar
 * sikligi BEYAZ LISTE (eski kod strtotime'a ham istemci metni veriyordu) ve
 * ust sinir (yanlislikla yuzlerce blok olusmasin).
 */
final class SaatKapama
{
    public const MAKS_TEKRAR = 52;

    /** Kabul edilen siklik: "+1 day", "+2 weeks", "+1 month" ... */
    private const SIKLIK = '/^\+(\d{1,2}) (day|days|week|weeks|month|months)$/';

    /**
     * Tekrarlayan blok tarihleri. tekrarSayisi <= 1 → yalniz baslangic.
     * Eski davranis: toplam tekrarSayisi kadar tarih, her biri bir oncekine siklik eklenerek.
     *
     * @return string[] Y-m-d listesi
     */
    public static function tarihler(string $baslangic, int $tekrarSayisi = 1, ?string $siklik = null, int $maks = self::MAKS_TEKRAR): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $baslangic) || strtotime($baslangic) === false) {
            throw new InvalidArgumentException('Tarih geçersiz.');
        }
        if ($tekrarSayisi > $maks) {
            throw new InvalidArgumentException('En fazla '.$maks.' tekrar yapılabilir.');
        }
        $tarihler = [$baslangic];
        if ($tekrarSayisi <= 1) {
            return $tarihler;
        }
        if (! $siklik || ! preg_match(self::SIKLIK, trim($siklik), $m) || (int) $m[1] < 1) {
            throw new InvalidArgumentException('Tekrar sıklığı geçersiz.');
        }
        $t = $baslangic;
        for ($i = 1; $i < $tekrarSayisi; $i++) {
            $t = date('Y-m-d', strtotime(trim($siklik), strtotime($t)));
            $tarihler[] = $t;
        }

        return $tarihler;
    }

    /** "HH:MM" | "HH:MM:SS" → "HH:MM:SS"; gecersizse hata. */
    public static function saat(string $s): string
    {
        $s = trim($s);
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $s)) {
            throw new InvalidArgumentException('Saat geçersiz: '.$s);
        }

        return strlen($s) === 5 ? $s.':00' : $s;
    }

    /** Bitis baslangictan SONRA olmali; dakika cinsinden sure. */
    public static function sureDk(string $baslangic, string $bitis): int
    {
        $dk = intdiv(strtotime('1970-01-01 '.self::saat($bitis)) - strtotime('1970-01-01 '.self::saat($baslangic)), 60);
        if ($dk <= 0) {
            throw new InvalidArgumentException('Bitiş saati başlangıçtan sonra olmalı.');
        }

        return $dk;
    }
}
