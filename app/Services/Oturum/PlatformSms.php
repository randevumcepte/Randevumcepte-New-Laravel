<?php

namespace App\Services\Oturum;

use App\Services\Sms\Gonderim\VoiceTelekomGonderici;
use App\Services\Sms\Rapor\SaglayiciHatasi;
use Illuminate\Support\Facades\Log;

/**
 * Platform adina anlik SMS (dogrulama kodu, deneme sifresi). Kuyruga yazilmaz: icerik gizli bilgi tasir,
 * tabloda duz metin kalmasin; kullanici da kodu ekranda bekliyor. Hata firlatmaz, sonucu dondurur.
 */
class PlatformSms
{
    public function __construct(private VoiceTelekomGonderici $gonderici) {}

    public function gonder(string $telefon, string $mesaj, string $baslik): bool
    {
        $ayar = (array) config('oturum.platform_sms');
        $hesap = (object) ['sms_user_name' => $ayar['kullanici'] ?? '', 'sms_secret' => $ayar['sifre'] ?? '', 'sms_baslik' => $ayar['baslik'] ?? ''];
        if ($hesap->sms_user_name === '' || $hesap->sms_user_name === null) {
            Log::error('PlatformSms: PLATFORM_SMS_KULLANICI tanimli degil');

            return false;
        }

        try {
            $this->gonderici->gonder($hesap, [$telefon], $mesaj, $baslik, (int) $ayar['zaman_asimi_sn']);

            return true;
        } catch (SaglayiciHatasi $e) {
            Log::error('PlatformSms gonderilemedi: '.$e->getMessage());

            return false;
        }
    }
}
