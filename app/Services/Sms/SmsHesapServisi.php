<?php

namespace App\Services\Sms;

use App\Exceptions\BulunamadiException;
use Illuminate\Support\Facades\DB;

/**
 * Salonun SMS hesap satırı (sağlayıcı kimlikleri + ayar kolonları) — tek okuma noktası.
 * Bakiye, rapor, gönderim ve açılış aynı satırı buradan alır; kimlik alanları API'ye çıkmaz.
 */
class SmsHesapServisi
{
    private const KOLONLAR = [
        'id', 'yeni_sms', 'sms_user_name', 'sms_secret', 'sms_apikey', 'sms_baslik', 'randevu_sms_hatirlatma', 'kara_liste_aktif',
    ];

    public function bul(int $salonId): ?object
    {
        return DB::table('salonlar')->where('id', $salonId)->first(self::KOLONLAR);
    }

    public function zorunlu(int $salonId): object
    {
        return $this->bul($salonId) ?? throw new BulunamadiException('İşletme bulunamadı');
    }
}
