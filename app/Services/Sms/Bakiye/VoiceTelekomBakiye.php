<?php

namespace App\Services\Sms\Bakiye;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VoiceTelekom (salonlar.yeni_sms=1) — eski App\VoiceTelekom\Sms\SmsApi::getCredit ile ayni uc:
 * POST user/credit, Basic auth (sms_user_name:sms_secret) → {err:null, data:{credit}}.
 */
class VoiceTelekomBakiye implements SmsBakiyeSaglayici
{
    public function bakiye(object $salon): ?int
    {
        $kullanici = (string) ($salon->sms_user_name ?? '');
        if ($kullanici === '') {
            return null;
        }
        try {
            $cevap = Http::timeout((int) config('sms.zaman_asimi_sn', 5))
                ->withBasicAuth($kullanici, (string) ($salon->sms_secret ?? ''))
                ->acceptJson()
                ->withBody('', 'application/json')
                ->post((string) config('sms.voicetelekom.kredi_url'));
            $govde = $cevap->json();
            if (! is_array($govde) || ($govde['err'] ?? null) !== null || ! isset($govde['data']['credit'])) {
                return null;
            }

            return (int) $govde['data']['credit'];
        } catch (\Throwable $e) {
            Log::warning('VoiceTelekom SMS bakiyesi okunamadi', ['salon' => $salon->id ?? null, 'hata' => $e->getMessage()]);

            return null;
        }
    }
}
