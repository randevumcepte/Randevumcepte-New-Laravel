<?php

namespace App\Services\Sms\Bakiye;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Efetech (eski varsayilan saglayici) — POST v2/get/balance, "Authorization: Key <sms_apikey>"
 * → {response:{balance}}.
 */
class EfetechBakiye implements SmsBakiyeSaglayici
{
    public function bakiye(object $salon): ?int
    {
        $anahtar = (string) ($salon->sms_apikey ?? '');
        if ($anahtar === '') {
            return null;
        }
        try {
            $cevap = Http::timeout((int) config('sms.zaman_asimi_sn', 5))
                ->withHeaders(['Authorization' => 'Key '.$anahtar])
                ->acceptJson()
                ->withBody('', 'application/json')
                ->post((string) config('sms.efetech.bakiye_url'));
            $bakiye = $cevap->json('response.balance');

            return is_numeric($bakiye) ? (int) $bakiye : null;
        } catch (\Throwable $e) {
            Log::warning('Efetech SMS bakiyesi okunamadi', ['salon' => $salon->id ?? null, 'hata' => $e->getMessage()]);

            return null;
        }
    }
}
