<?php

namespace App\Domain\Hesabim;

/**
 * Üyelik paket / periyot sözlüğü — eski hesabim() + hesabimApiFeed() içindeki kopya dizilerin
 * TEK kaynağı. salonlar.uyelik_turu paket KADEMESİDİR (1/2/3), demo bilgisi değildir.
 */
final class UyelikKatalogu
{
    private const PAKETLER = [1 => 'Başlangıç', 2 => 'Standart', 3 => 'Premium'];

    private const PERIYOTLAR = [1 => 'Aylık', 2 => 'Yıllık'];

    private const PERIYOT_KODLARI = ['aylik' => 'Aylık', 'yillik' => 'Yıllık'];

    private const PREMIUM = 3;

    public static function paketAdi(int|string|null $tur): ?string
    {
        return self::PAKETLER[(int) $tur] ?? null;
    }

    public static function periyotAdi(int|string|null $periyot): ?string
    {
        return self::PERIYOTLAR[(int) $periyot] ?? null;
    }

    /** salon_uyelik_odemeleri.periyot ('aylik'|'yillik') → görünen ad. */
    public static function periyotKodAdi(?string $kod): ?string
    {
        return self::PERIYOT_KODLARI[(string) $kod] ?? null;
    }

    public static function premiumMu(int|string|null $tur): bool
    {
        return (int) $tur === self::PREMIUM;
    }
}
