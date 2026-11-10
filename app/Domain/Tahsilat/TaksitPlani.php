<?php

namespace App\Domain\Tahsilat;

use App\Exceptions\GecersizIslemException;
use Carbon\Carbon;

/**
 * Taksit vadeleri: tarih ve tutar. Web ve mobil taksitin tek kuralı.
 *
 * Eski koddaki iki hata düzeltildi:
 * - Tutar toplam / vade yuvarlanıyordu (1000 / 3 → 3 × 333,33, 1 kuruş kayıp). Burada vadelerin toplamı plana eşittir;
 *   artık kuruşlar ilk vadelere birer birer eklenir.
 * - Tarih bir öncekine +1 ay ekleniyordu (31 Ocak → 3 Mart → 3 Nisan). Burada her vade başlangıçtan i ay sonradır,
 *   ay sonuna kırpılır (31 Ocak → 28 Şubat → 31 Mart).
 */
final class TaksitPlani
{
    public const AZAMI_VADE = 60;

    /** @return list<array{tarih:string, tutar_kurus:int}> */
    public static function vadeler(string $baslangic, int $vadeSayisi, int $toplamKurus): array
    {
        if ($vadeSayisi < 1 || $vadeSayisi > self::AZAMI_VADE) {
            throw new GecersizIslemException('Taksit sayısı 1 ile '.self::AZAMI_VADE.' arasında olmalı.');
        }
        if ($toplamKurus <= 0) {
            throw new GecersizIslemException('Taksit tutarı 0’dan büyük olmalı.');
        }

        $tutarlar = self::tutarlar($toplamKurus, $vadeSayisi);

        return array_map(fn ($tarih, $tutar) => ['tarih' => $tarih, 'tutar_kurus' => $tutar],
            self::tarihler($baslangic, $vadeSayisi), $tutarlar);
    }

    /** @return list<int> */
    public static function tutarlar(int $toplamKurus, int $vadeSayisi): array
    {
        $taban = intdiv($toplamKurus, $vadeSayisi);
        $artik = $toplamKurus - $taban * $vadeSayisi;

        return array_map(fn ($i) => $taban + ($i < $artik ? 1 : 0), range(0, $vadeSayisi - 1));
    }

    /** @return list<string> */
    public static function tarihler(string $baslangic, int $vadeSayisi): array
    {
        try {
            $ilk = Carbon::createFromFormat('!Y-m-d', $baslangic);
        } catch (\Throwable) {
            $ilk = null;
        }
        if (! $ilk || $ilk->format('Y-m-d') !== $baslangic) {
            throw new GecersizIslemException('Ödeme başlangıç tarihi geçersiz.');
        }

        return array_map(fn ($i) => $ilk->copy()->addMonthsNoOverflow($i)->format('Y-m-d'), range(0, $vadeSayisi - 1));
    }
}
