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
 */
final class HizmetZinciri
{
    /**
     * @param  string  $baslangic  "H:i" ya da "H:i:s"
     * @param  array<int,array{sure_dk:mixed,birlestir?:mixed}>  $hizmetler
     * @return array<int,array{saat:string,saat_bitis:string,sure_dk:int}>  ayni index
     */
    public static function satirlar(string $baslangic, array $hizmetler): array
    {
        $t = self::dakika($baslangic);
        $sonuc = [];
        foreach (array_values($hizmetler) as $i => $h) {
            $sure = (int) ($h['sure_dk'] ?? 0);
            if ($sure < 5) {
                throw new InvalidArgumentException('Hizmet '.($i + 1).': süre en az 5 dk olmalı.');
            }
            $bas = $t;
            $bit = $bas + $sure;
            if ($bit > 24 * 60) {
                throw new InvalidArgumentException('Randevu gece yarısını geçemez.');
            }
            $sonuc[] = ['saat' => self::saat($bas), 'saat_bitis' => self::saat($bit), 'sure_dk' => $sure];
            if ((string) ($h['birlestir'] ?? '') !== '1') {
                $t = $bit; // ardisik
            }
        }

        return $sonuc;
    }

    /** Randevu basligi bitisi — eski kod TUM surelerin toplami (paralel dahil). */
    public static function toplamBitis(string $baslangic, array $hizmetler): string
    {
        $toplam = array_sum(array_map(fn ($h) => (int) ($h['sure_dk'] ?? 0), $hizmetler));

        return self::saat(min(self::dakika($baslangic) + $toplam, 24 * 60));
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
