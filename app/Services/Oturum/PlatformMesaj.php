<?php

namespace App\Services\Oturum;

use App\Services\SistemYonetim\SistemBildirimServisi;
use App\Services\Whatsapp\Kopru\WhatsappKoprusu;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Anlik gizli mesaj (dogrulama kodu, deneme sifresi). gonder(): platform hatti, once sistem WhatsApp'i,
 * olmazsa SMS. kod(): salon biliniyorsa once salonun hatti. Kuyruga yazilmaz (PlatformSms ile ayni neden).
 * Hata firlatmaz.
 */
class PlatformMesaj
{
    private const KANAL_HAFIZA_SN = 600;

    public function __construct(
        private WhatsappKoprusu $kopru,
        private SistemBildirimServisi $sistem,
        private PlatformSms $sms,
        private SalonMesaj $salon,
    ) {}

    public function gonder(string $telefon, string $mesaj, string $baslik, bool $whatsappDene = true): bool
    {
        if ($whatsappDene && config('oturum.kod_whatsapp')
            && $this->kopru->gonder($this->sistem->oturumId(), SistemBildirimServisi::normalize($telefon), $mesaj)->basarili()) {
            return true;
        }

        return $this->sms->gonder($telefon, $mesaj, $baslik);
    }

    /**
     * Dogrulama kodu, canli sistemle ayni sira: isletmenin WhatsApp'i, isletmenin SMS'i; bunlar yoksa ya da
     * basarisizsa platform hatti (kimse girissiz kalmasin). Kullanici yeniden isterse WhatsApp atlanir:
     * WhatsApp'i olmayan numara kodsuz kalmasin.
     */
    public function kod(string $telefon, string $mesaj, string $baslik, ?int $salonId = null): bool
    {
        $anahtar = 'platform-kod-kanal:'.$telefon;
        $whatsappDene = ! Cache::has($anahtar);
        Cache::put($anahtar, 1, self::KANAL_HAFIZA_SN);

        if ($salonId !== null && $whatsappDene && $this->salon->whatsapp($salonId, $telefon, $mesaj)) {
            Log::info('Dogrulama kodu gonderildi', ['kanal' => 'salon_whatsapp', 'salon_id' => $salonId]);

            return true;
        }
        if ($salonId !== null && $this->salon->sms($salonId, $telefon, $mesaj, $baslik)) {
            Log::info('Dogrulama kodu gonderildi', ['kanal' => 'salon_sms', 'salon_id' => $salonId]);

            return true;
        }

        $sonuc = $this->gonder($telefon, $mesaj, $baslik, $whatsappDene);
        if ($sonuc) {
            Log::info('Dogrulama kodu gonderildi', ['kanal' => 'platform', 'salon_id' => $salonId]);
        } else {
            Log::error('Dogrulama kodu hicbir kanaldan gonderilemedi', ['salon_id' => $salonId]);
        }

        return $sonuc;
    }
}
