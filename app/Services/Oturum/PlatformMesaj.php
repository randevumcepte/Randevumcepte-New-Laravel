<?php

namespace App\Services\Oturum;

use App\Services\SistemYonetim\SistemBildirimServisi;
use App\Services\Whatsapp\Kopru\WhatsappKoprusu;
use Illuminate\Support\Facades\Cache;

/**
 * Platform adina anlik gizli mesaj (dogrulama kodu, deneme sifresi): once sistem WhatsApp hatti, olmazsa
 * SMS. Kuyruga yazilmaz (PlatformSms ile ayni neden), salon bakiyesinden dusmez. Hata firlatmaz.
 */
class PlatformMesaj
{
    private const KANAL_HAFIZA_SN = 600;

    public function __construct(
        private WhatsappKoprusu $kopru,
        private SistemBildirimServisi $sistem,
        private PlatformSms $sms,
    ) {}

    public function gonder(string $telefon, string $mesaj, string $baslik, bool $whatsappDene = true): bool
    {
        if ($whatsappDene && config('oturum.kod_whatsapp')
            && $this->kopru->gonder($this->sistem->oturumId(), SistemBildirimServisi::normalize($telefon), $mesaj)->basarili()) {
            return true;
        }

        return $this->sms->gonder($telefon, $mesaj, $baslik);
    }

    /** Dogrulama kodu: ilk gonderim WhatsApp, kullanici yeniden isterse SMS (WhatsApp'i olmayan numara kodsuz kalmasin). */
    public function kod(string $telefon, string $mesaj, string $baslik): bool
    {
        $anahtar = 'platform-kod-kanal:'.$telefon;
        $sonuc = $this->gonder($telefon, $mesaj, $baslik, ! Cache::has($anahtar));
        Cache::put($anahtar, 1, self::KANAL_HAFIZA_SN);

        return $sonuc;
    }
}
