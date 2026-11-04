<?php

namespace App\Domain\Randevu;

/**
 * Salon sahibinin online randevu kurasyonu — SAF. Eski App\SalonOnlineRandevuSaatleri'nin
 * slotIzinliMi + seyrelt karari (web ve mobil ayni sonucu alsin diye tek yer).
 *
 * Pencere degeri: null = kisitlama yok, [] = o gun kapali, [[bas, bit], ...] = acik pencereler (dk).
 * Yalniz BASLANGIC pencerede olmalidir (mobil v1 ile ayni karar: "kapali dilim" baslangic
 * olarak onerme demektir, o dilimi bos tutma zorunlulugu degil).
 */
final class OnlineSaatKurali
{
    public static function izinliMi(?array $pencereler, int $baslangic): bool
    {
        if ($pencereler === null) {
            return true;
        }
        foreach ($pencereler as [$bas, $bit]) {
            if ($baslangic >= $bas && $baslangic < $bit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gunluk limit: sirali listeden esit aralikla en fazla $limit eleman (eski seyrelt).
     *
     * @return array<int,mixed> secilenler (sira korunur)
     */
    public static function seyrelt(array $sirali, int $limit): array
    {
        $sirali = array_values($sirali);
        $n = count($sirali);
        if ($limit <= 0 || $n <= $limit) {
            return $sirali;
        }
        if ($limit === 1) {
            return [$sirali[(int) floor($n / 2)]];
        }
        $secili = [];
        for ($i = 0; $i < $limit; $i++) {
            $secili[] = $sirali[(int) round($i * ($n - 1) / ($limit - 1))];
        }

        return $secili;
    }
}
