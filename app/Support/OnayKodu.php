<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Tek kullanimlik SMS onay kodu (kasadan para cekme, kasadaki tahsilati silme vb.).
 *
 * Eski kodun aciklari kapatildi: kod isletme_yetkilileri / tahsilatlar tablosuna duz
 * yaziliyor, hic temizlenmiyor (tekrar kullanilabiliyordu), gevsek (==) karsilastiriliyor
 * ve hic uretilmemisse BOS kod kabul ediliyordu. Burada: rastgele 4 hane, yalniz hash'i
 * saklanir, islem baglamina (tutar, kayit id) baglidir, 10 dk gecerli, 5 yanlis denemede
 * duser, dogru kullanimda silinir.
 */
final class OnayKodu
{
    private const SURE_SN = 600;

    private const MAKS_DENEME = 5;

    /** Yeni kod uretir (oncekini gecersiz kilar) ve SMS'e konacak duz kodu dondurur. */
    public static function uret(string $amac, int $salonId, int $yetkiliId, array $baglam = [], int $hane = 4, int $sureSn = self::SURE_SN): string
    {
        $kod = str_pad((string) random_int(0, 10 ** $hane - 1), $hane, '0', STR_PAD_LEFT);
        Cache::put(self::anahtar($amac, $salonId, $yetkiliId), [
            'hash'   => hash('sha256', $kod.'|'.self::baglamIzi($baglam)),
            'deneme' => 0,
            'bitis'  => time() + $sureSn,
        ], $sureSn);

        return $kod;
    }

    /** Kod dogru ve baglam ayniysa true (ve kod tuketilir); degilse deneme sayaci artar. */
    public static function dogrula(string $amac, int $salonId, int $yetkiliId, ?string $kod, array $baglam = []): bool
    {
        $anahtar = self::anahtar($amac, $salonId, $yetkiliId);
        $kayit = Cache::get($anahtar);
        $kod = trim((string) $kod);
        if (! $kayit || $kod === '') {
            return false;
        }
        if (hash_equals($kayit['hash'], hash('sha256', $kod.'|'.self::baglamIzi($baglam)))) {
            Cache::forget($anahtar);

            return true;
        }
        $kayit['deneme']++;
        // Yanlis deneme kodun omrunu uzatmaz.
        $kalan = (int) ($kayit['bitis'] ?? 0) - time();
        $kayit['deneme'] >= self::MAKS_DENEME || $kalan <= 0 ? Cache::forget($anahtar) : Cache::put($anahtar, $kayit, $kalan);

        return false;
    }

    private static function anahtar(string $amac, int $salonId, int $yetkiliId): string
    {
        return "v2_onay_kodu:{$amac}:{$salonId}:{$yetkiliId}";
    }

    private static function baglamIzi(array $baglam): string
    {
        ksort($baglam);

        return json_encode($baglam);
    }
}
