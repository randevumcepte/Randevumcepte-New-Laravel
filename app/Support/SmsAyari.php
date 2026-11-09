<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Salonun SMS ayari (salon_sms_ayarlari) — TEK okuma noktasi. Her ayarin iki anahtari var:
 * 'musteri' (musteriye giden) ve 'personel' (isletme tarafina giden). Ornek: 16 musteri dogrulama kodu
 * (geldi isareti), 22 KVKK onay kodu, 23 silmede hesap sahibi onayi, 19/20 kasa bildirimleri.
 */
final class SmsAyari
{
    public static function musteri(int $salonId, int $ayarId): bool
    {
        return self::oku($salonId, $ayarId, 'musteri');
    }

    public static function personel(int $salonId, int $ayarId): bool
    {
        return self::oku($salonId, $ayarId, 'personel');
    }

    private static function oku(int $salonId, int $ayarId, string $kolon): bool
    {
        return (bool) DB::table('salon_sms_ayarlari')->where('salon_id', $salonId)->where('ayar_id', $ayarId)->value($kolon);
    }
}
