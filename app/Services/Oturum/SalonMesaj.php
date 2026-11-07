<?php

namespace App\Services\Oturum;

use App\Domain\Sms\SmsSaglayici;
use App\Services\SistemYonetim\SistemBildirimServisi;
use App\Services\Sms\Gonderim\AnlikSmsGonderici;
use App\Services\Sms\SmsHesapServisi;
use App\Services\Whatsapp\Kopru\WhatsappKoprusu;
use App\Services\Whatsapp\WhatsappOturumServisi;
use Illuminate\Support\Facades\Log;

/**
 * Salonun kendi hattindan anlik gizli mesaj (dogrulama kodu). Kuyruga yazilmaz. Hata firlatmaz, sonucu
 * dondurur; cagiran bir sonraki kanala gecer.
 */
class SalonMesaj
{
    public function __construct(
        private WhatsappOturumServisi $oturum,
        private WhatsappKoprusu $kopru,
        private SmsHesapServisi $hesap,
        private AnlikSmsGonderici $sms,
    ) {}

    public function whatsapp(int $salonId, string $telefon, string $mesaj): bool
    {
        try {
            $o = $this->oturum->coz($salonId);

            return $o !== null && $o['durum']->bagli
                && $this->kopru->gonder((int) $o['oturum']->id, SistemBildirimServisi::normalize($telefon), $mesaj)->basarili();
        } catch (\Throwable $e) {
            Log::warning('SalonMesaj WhatsApp gonderilemedi: '.$e->getMessage(), ['salon_id' => $salonId]);

            return false;
        }
    }

    public function sms(int $salonId, string $telefon, string $mesaj, string $baslik): bool
    {
        try {
            $salon = $this->hesap->bul($salonId);
            if (! $salon || ! SmsSaglayici::salondan($salon)->hesapTanimli($salon)) {
                return false;
            }
            $this->sms->gonder($salon, $telefon, $mesaj, $baslik, (int) config('oturum.platform_sms.zaman_asimi_sn'));

            return true;
        } catch (\Throwable $e) {
            Log::warning('SalonMesaj SMS gonderilemedi: '.$e->getMessage(), ['salon_id' => $salonId]);

            return false;
        }
    }
}
