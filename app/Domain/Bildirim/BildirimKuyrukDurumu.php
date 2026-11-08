<?php

namespace App\Domain\Bildirim;

/**
 * Yeni isleyicinin durumlari (v2_bildirim_kuyrugu.durum). 6 ve 7 BILEREK ayri: eski monolitin `v2:bildirim-isle` komutu yalniz
 * 0 alir ve 5 dk'lik 3'leri 0'a cevirir, bu degerleri gormez. Iki isleyici ayni anda calisir, satirlari karismaz, eski koda
 * dokunulmaz. Bayrak kapaliyken satir 0 yazilir ve eski isleyici gonderir; acikken 6 yazilir ve yeni isleyici gonderir.
 * Sonuc durumlari ortaktir. 4 ve 5: SmsKuyrukDurumu.
 */
final class BildirimKuyrukDurumu
{
    public const ESKI_ISLEYICI = 0;

    public const GONDERILDI = 1;

    public const HATA = 2;

    public const BEKLIYOR = 6;

    public const ISLENIYOR = 7;

    /** Yeni yazilan satirin durumu: hangi isleyici gonderecek. 'eski_isleyicide_kalan' turler bayrak acikken de eskide kalir. */
    public static function yeniSatir(?string $tur = null): int
    {
        if ($tur !== null && in_array($tur, (array) config('bildirim_kuyrugu.eski_isleyicide_kalan'), true)) {
            return self::ESKI_ISLEYICI;
        }

        return config('bildirim_kuyrugu.isleyici_aktif') ? self::BEKLIYOR : self::ESKI_ISLEYICI;
    }
}
