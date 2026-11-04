<?php

namespace App\Domain\Ders;

/**
 * Grup dersi bildirim metinleri — SAF, TEK kaynak (eski panel + mobil ayni metni
 * iki ayri yerde tutuyordu, terfi metni de farkliydi; panel metni kanonik alindi).
 * Gonderim outbox'la eski monolitte: 'ders_bildirim' → DersBildirimServisi (WA-first/SMS).
 */
final class DersMesaji
{
    /** Bu kadar veya daha az ders kalinca "yenileme" uyarisi gider. */
    public const KALAN_UYARI_ESIK = 3;

    public static function kayit(string $ad, string $tarih, string $saat, ?string $ders): string
    {
        return self::giris($ad, $tarih, $saat, $ders).' dersine kaydınız oluşturuldu. Görüşmek üzere ✨';
    }

    public static function bekleme(string $ad, string $tarih, string $saat, ?string $ders): string
    {
        return self::giris($ad, $tarih, $saat, $ders).' dersi kapasitesi dolu olduğundan bekleme listesine alındınız. Yer açılırsa bilgilendireceğiz.';
    }

    public static function terfi(string $ad, string $tarih, string $saat, ?string $ders): string
    {
        return self::giris($ad, $tarih, $saat, $ders).' dersinde yeriniz açıldı, kaydınız onaylandı. Görüşmek üzere ✨';
    }

    public static function iptal(string $ad, string $tarih, string $saat, ?string $ders): string
    {
        return self::giris($ad, $tarih, $saat, $ders).' dersiniz iptal edilmiştir. Bilginize.';
    }

    /** Esik ustundeyse null (uyari gitmez). */
    public static function kalanUyari(string $ad, string $hizmet, int $kalan): ?string
    {
        if ($kalan > self::KALAN_UYARI_ESIK) {
            return null;
        }

        return $kalan <= 0
            ? "Sayın {$ad}; {$hizmet} paketinizdeki ders hakkınız doldu. Devam etmek için paketinizi yenileyebilirsiniz. Bilgi için bize ulaşın 🌸"
            : "Sayın {$ad}; {$hizmet} paketinizde {$kalan} dersiniz kaldı. Aksatmadan devam etmek için yeni paketinizi şimdiden planlayabilirsiniz 🌸";
    }

    private static function giris(string $ad, string $tarih, string $saat, ?string $ders): string
    {
        $gun = date('d.m.Y', strtotime($tarih));

        return "Sayın {$ad}; {$gun} saat ".substr($saat, 0, 5).' '.($ders ?: 'Grup Dersi');
    }
}
