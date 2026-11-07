<?php

namespace App\Domain\Randevu;

use InvalidArgumentException;

/**
 * Randevu hizmet satirlarinin zaman penceresi — SAF (DB yok, unit-test edilir).
 *
 * Eski randevuekleguncelle / cakisma fonksiyonlariyla AYNI zincir kurali:
 *   - ilk satir randevu saatinde baslar;
 *   - birlestir != '1' ise sonraki satir bu satirin BITISINDE baslar (ardisik);
 *   - birlestir == '1' ise sonraki satir AYNI baslangicta baslar (paralel).
 * Fark: eski kodda bos sure_dk bazi yerlerde 0, bazi yerlerde 60, kayitta 30
 * sayiliyordu (kontrol ile kayit tutarsizdi). Burada sure ZORUNLU (>= 5 dk).
 * $serbest (mobil istek): eski kayit kurali; bos sure 30 dk, alt sinir yok, gece yarisini gecen saat basa sarar.
 */
final class HizmetZinciri
{
    /**
     * @param  string  $baslangic  "H:i" ya da "H:i:s"
     * @param  array<int,array{sure_dk:mixed,birlestir?:mixed}>  $hizmetler
     * @return array<int,array{saat:string,saat_bitis:string,sure_dk:int}>  ayni index
     */
    public static function satirlar(string $baslangic, array $hizmetler, bool $serbest = false): array
    {
        $t = self::dakika($baslangic);
        $sonuc = [];
        foreach (array_values($hizmetler) as $i => $h) {
            $sure = self::sure($h, $serbest);
            if (! $serbest && $sure < 5) {
                throw new InvalidArgumentException('Hizmet '.($i + 1).': süre en az 5 dk olmalı.');
            }
            $bas = $t;
            $bit = $bas + $sure;
            if ($bit > 24 * 60) {
                if (! $serbest) {
                    throw new InvalidArgumentException('Randevu gece yarısını geçemez.');
                }
                $bit %= 24 * 60;
            }
            $sonuc[] = ['saat' => self::saat($bas), 'saat_bitis' => self::saat($bit), 'sure_dk' => $sure];
            if ((string) ($h['birlestir'] ?? '') !== '1') {
                $t = $bit; // ardisik
            }
        }

        return $sonuc;
    }

    /** Randevu basligi bitisi — eski kod TUM surelerin toplami (paralel dahil). */
    public static function toplamBitis(string $baslangic, array $hizmetler, bool $serbest = false): string
    {
        $bitis = self::dakika($baslangic) + array_sum(array_map(fn ($h) => self::sure($h, $serbest), $hizmetler));

        return self::saat($serbest ? $bitis % (24 * 60) : min($bitis, 24 * 60));
    }

    private static function sure(array $hizmet, bool $serbest): int
    {
        $sure = $hizmet['sure_dk'] ?? null;

        return $serbest && ! is_numeric($sure) ? 30 : (int) $sure;
    }

    public static function dakika(string $saat): int
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim($saat), $m)) {
            throw new InvalidArgumentException('Saat geçersiz: '.$saat);
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    public static function saat(int $dakika): string
    {
        return $dakika >= 24 * 60 ? '24:00' : sprintf('%02d:%02d', intdiv($dakika, 60), $dakika % 60);
    }
}
