<?php

namespace App\Domain\Ayar;

/**
 * Haftalik saat hesaplari — SAF mantik (salon calisma/mola, online randevu pencereleri).
 *
 * Gun numarasi 1=Pazartesi … 7=Pazar (eski tablolarla ayni). Saatler 'HH:MM'.
 * Online randevu slot uretimi + bitisik slotlari pencereye birlestirme eski
 * ApiController@onlineRandevuSaatConfig / onlineRandevuSaatKaydet ile BIREBIR.
 */
final class HaftalikSaat
{
    public const GUNLER = [
        1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe',
        5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar',
    ];

    /** Randevu araligi gecersizse (bos / 5 dk alti) kullanilan deger — eskiyle ayni. */
    public const VARSAYILAN_ARALIK_DK = 30;

    public static function gecerliGun($gun): bool
    {
        return isset(self::GUNLER[(int) $gun]);
    }

    /** 'HH:MM' ya da 'HH:MM:SS' → 'HH:MM'; gecersizse null. */
    public static function saat($deger): ?string
    {
        $v = substr(trim((string) $deger), 0, 5);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
    }

    public static function dakika(string $hhmm): int
    {
        return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
    }

    public static function hhmm(int $dakika): string
    {
        return sprintf('%02d:%02d', intdiv($dakika, 60), $dakika % 60);
    }

    public static function aralik($dk): int
    {
        $dk = (int) $dk;

        return $dk >= 5 ? $dk : self::VARSAYILAN_ARALIK_DK;
    }

    /** Bitis baslangictan sonra mi? (ikisi de gecerli 'HH:MM' olmali) */
    public static function sirali(?string $bas, ?string $bit): bool
    {
        return $bas !== null && $bit !== null && self::dakika($bit) > self::dakika($bas);
    }

    /**
     * [bas, bit) araligini $aralikDk adimla slotlara boler.
     *
     * @return list<string>
     */
    public static function slotlar(string $bas, string $bit, int $aralikDk): array
    {
        $slotlar = [];
        for ($t = self::dakika($bas), $son = self::dakika($bit); $t < $son; $t += $aralikDk) {
            $slotlar[] = self::hhmm($t);
        }

        return $slotlar;
    }

    /**
     * Acik slot listesini bitisik pencerelere birlestirir (her pencere bitisi = son slot + aralik).
     * Gecersiz saatler elenir, tekrarlar teklenir.
     *
     * @param  iterable<string>  $slotlar
     * @return list<array{bas:string,bit:string}>
     */
    public static function pencereler(iterable $slotlar, int $aralikDk): array
    {
        $dk = [];
        foreach ($slotlar as $s) {
            if (($h = self::saat($s)) !== null) {
                $dk[] = self::dakika($h);
            }
        }
        $dk = array_values(array_unique($dk));
        sort($dk);
        if (! $dk) {
            return [];
        }

        $pencereler = [];
        $bas = $onceki = $dk[0];
        foreach (array_slice($dk, 1) as $t) {
            if ($t === $onceki + $aralikDk) {
                $onceki = $t;

                continue;
            }
            $pencereler[] = ['bas' => self::hhmm($bas), 'bit' => self::hhmm(min($onceki + $aralikDk, 24 * 60 - 1))];
            $bas = $onceki = $t;
        }
        $pencereler[] = ['bas' => self::hhmm($bas), 'bit' => self::hhmm(min($onceki + $aralikDk, 24 * 60 - 1))];

        return $pencereler;
    }
}
