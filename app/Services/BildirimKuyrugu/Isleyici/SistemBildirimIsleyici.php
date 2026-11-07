<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\SistemYonetim\SistemBildirimServisi;
use App\Services\Whatsapp\Kopru\WhatsappKoprusu;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sistem sahibine bildirim; eski App\Services\SistemBildirim::gonder. Alici ve oturum SistemBildirimServisi'nden, ayni ayar
 * dosyasindan gelir. SistemBildirimServisi::gonder kullanilmaz: SMS'i PlatformSms ile yollar, eski yol ise Efetech sistem
 * SMS'idir (SmsKanali::gonder('', ...)). Her aliciya WA + SMS birlikte gider; WA gitse de SMS gider. Kanal hatalari yutulur.
 * Kanal kapali ya da alici yoksa hata olarak kalir (talep sessizce kaybolmasin).
 */
class SistemBildirimIsleyici implements BildirimIsleyici
{
    public function __construct(
        private SistemBildirimServisi $sistem,
        private WhatsappKoprusu $kopru,
        private SmsKanali $sms,
    ) {}

    public function isle(object $kayit, array $payload): void
    {
        $mesaj = (string) ($payload['mesaj'] ?? '');
        $alicilar = $this->sistem->alicilar();
        if (! $this->sistem->ayar()['aktif'] || ! $alicilar) {
            Log::warning('[SistemBildirim] gonder ATLANDI — kapali veya alici numara yok', ['alici_sayisi' => count($alicilar)]);
            throw new RuntimeException('sistem_bildirim: kapali-veya-numara-yok');
        }
        // Eskide anahtar kaynakta sabitti; bos anahtarla Efetech cevabi yorumlanmadigindan talep sessizce kaybolurdu.
        if (trim((string) config('bildirim_kuyrugu.sistem_sms.apikey')) === '') {
            throw new RuntimeException('sistem_bildirim: SMS_EFETECH_SISTEM_APIKEY yok');
        }

        foreach ($alicilar as $numara) {
            try {
                $this->kopru->gonder($this->sistem->oturumId(), $numara, $mesaj);
            } catch (\Throwable $e) {
                Log::warning('[SistemBildirim] WA hata', ['e' => $e->getMessage()]);
            }
            try {
                // Efetech yerel bicim bekler: 90 soyulur.
                $this->sms->gonder('', [['to' => (string) preg_replace('/^90/', '', $numara), 'message' => $mesaj]]);
            } catch (\Throwable $e) {
                Log::warning('[SistemBildirim] SMS hata', ['e' => $e->getMessage()]);
            }
        }

        Log::info('[SistemBildirim] gonder tamam', ['alici_sayisi' => count($alicilar)]);
    }
}
