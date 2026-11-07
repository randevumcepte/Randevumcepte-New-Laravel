<?php

namespace App\Services\BildirimKuyrugu\Randevu;

use App\Domain\Sms\SmsSaglayici;
use App\Services\Sms\SmsHesapServisi;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Eski ApiController::kalan_sms; dogrulama kodunun kanal secimi buna bakar. Hazir SmsBakiyeServisi bilerek kullanilmaz: o 120 sn
 * onbellekli ve okuyamayinca null doner, kanal secimi degisirdi. Eskide:
 *   VoiceTelekom: 120 sn zaman asimi, her hata 0.
 *   Efetech     : sms_apikey null ise 0, 5 sn zaman asimi, 'balance' yoksa 0; gecersiz cevapta hata firlar, kayit yeniden denenir.
 */
class SmsKredisi
{
    private const VOICETELEKOM_ZAMAN_ASIMI_SN = 120;

    private const EFETECH_ZAMAN_ASIMI_SN = 5;

    public function __construct(private SmsHesapServisi $hesap) {}

    public function kalan(int|string|null $salonId): int
    {
        $salon = $this->hesap->bul((int) $salonId) ?? throw new RuntimeException('Salon bulunamadi: '.$salonId);

        return SmsSaglayici::salondan($salon) === SmsSaglayici::VoiceTelekom ? $this->voiceTelekom($salon) : $this->efetech($salon);
    }

    private function voiceTelekom(object $salon): int
    {
        try {
            $govde = Http::timeout(self::VOICETELEKOM_ZAMAN_ASIMI_SN)
                ->withoutVerifying()
                ->withBasicAuth((string) $salon->sms_user_name, (string) $salon->sms_secret)
                ->withBody('', 'application/json')
                ->post((string) config('sms.voicetelekom.kredi_url'))
                ->json();
        } catch (\Throwable) {
            return 0;
        }

        return is_array($govde) && array_key_exists('err', $govde) && $govde['err'] === null ? (int) ($govde['data']['credit'] ?? 0) : 0;
    }

    private function efetech(object $salon): int
    {
        if ($salon->sms_apikey === null) {
            return 0;
        }
        $govde = Http::timeout(self::EFETECH_ZAMAN_ASIMI_SN)
            ->withHeaders(['Authorization' => 'Key '.$salon->sms_apikey])
            ->acceptJson()
            ->withBody('', 'application/json')
            ->post((string) config('sms.efetech.bakiye_url'))
            ->json();
        if (! is_array($govde) || ! is_array($govde['response'] ?? null)) {
            throw new RuntimeException('SMS bakiyesi okunamadi: '.$salon->id);
        }

        return (int) ($govde['response']['balance'] ?? 0);
    }
}
