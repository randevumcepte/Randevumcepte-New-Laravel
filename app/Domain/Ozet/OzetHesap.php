<?php

namespace App\Domain\Ozet;

/**
 * Ozet kartlarinin SAF hesaplari (DB yok, birim-test edilebilir). Formuller eski
 * StoreAdminController::dashboardRandevuOzet / dashboardPersonelDurum / dashboardAnket ile
 * birebir; sabitler burada adlandirildi (eskide kod icine gomuluydu).
 */
final class OzetHesap
{
    /** Salon doluluk tahmininde gunluk calisma saati varsayimi. */
    public const CALISMA_SAAT = 10;

    /** Randevu araligi alt siniri (dk) — 0/bos ayar kapasiteyi patlatmasin. */
    public const MIN_ARALIK_DK = 15;

    /** Personel "anlik durum" gunluk randevu kapasitesi varsayimi. */
    public const PERSONEL_GUNLUK_KAPASITE = 16;

    /** Salon doluluk %: olusturulan / (personel × saat × saatlik slot × gun). Aralik ayarsizsa (null) 30 dk. */
    public static function salonDoluluk(int $randevu, int $personelSay, ?int $aralikDk, int $gunSay): int
    {
        $slot = 60 / max(self::MIN_ARALIK_DK, $aralikDk ?? 30);
        $kapasite = max(1, $personelSay) * self::CALISMA_SAAT * $slot * max(1, $gunSay);

        return (int) min(100, round($randevu / $kapasite * 100));
    }

    /** Personel bugunku doluluk %. */
    public static function personelDoluluk(int $randevu): int
    {
        return (int) min(100, round($randevu / self::PERSONEL_GUNLUK_KAPASITE * 100));
    }

    /** NPS = %promoter − %detractor; cevap yoksa null. */
    public static function nps(int $promoter, int $pasif, int $detractor): ?int
    {
        $toplam = $promoter + $pasif + $detractor;

        return $toplam > 0 ? (int) round(($promoter - $detractor) / $toplam * 100) : null;
    }

    /** Yuzde oran (bolen 0 → 0). */
    public static function oran(float $pay, float $payda, int $ondalik = 1): float
    {
        return $payda > 0 ? round($pay / $payda * 100, $ondalik) : 0.0;
    }

    /** Degisim % (onceki 0 → null: "yeni"). */
    public static function degisim(float $simdi, float $onceki): ?float
    {
        return $onceki > 0 ? round(($simdi - $onceki) / $onceki * 100, 1) : null;
    }
}
