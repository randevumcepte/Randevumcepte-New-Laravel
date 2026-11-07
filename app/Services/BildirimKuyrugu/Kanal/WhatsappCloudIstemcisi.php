<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Meta WhatsApp Cloud API sablon mesaji; eski WhatsAppCloudApiClient::sendTemplate. Salon satirinda cloud_api_* kolonlari olmali. */
class WhatsappCloudIstemcisi
{
    private const API_KOKU = 'https://graph.facebook.com/v21.0/';

    private const ZAMAN_ASIMI_SN = 15;

    /** @return array{ok:bool, messageId?:?string, error?:string, status:int} */
    public function sablonGonder(object $salon, string $telefon, string $sablon, array $parametreler = []): array
    {
        if (empty($salon->cloud_api_token) || empty($salon->cloud_api_phone_number_id)) {
            return ['ok' => false, 'error' => 'cloud-api-credentials-missing', 'status' => 0];
        }

        $govde = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $telefon,
            'type'              => 'template',
            'template'          => ['name' => $sablon, 'language' => ['code' => $salon->cloud_api_template_dil ?: 'tr']],
        ];
        if ($parametreler) {
            $govde['template']['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $parametreler),
            ]];
        }

        try {
            $cevap = Http::timeout(self::ZAMAN_ASIMI_SN)->acceptJson()->withToken((string) $salon->cloud_api_token)
                ->post(self::API_KOKU.$salon->cloud_api_phone_number_id.'/messages', $govde);
        } catch (\Throwable $e) {
            Log::warning('[WA Cloud] curl error', ['salon_id' => $salon->id, 'err' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'service-unreachable', 'status' => 0];
        }

        $durum = $cevap->status();
        $cozulen = $cevap->json();
        if ($durum >= 200 && $durum < 300) {
            return ['ok' => true, 'messageId' => $cozulen['messages'][0]['id'] ?? null, 'status' => $durum];
        }

        $hata = $cozulen['error']['message'] ?? ($cozulen['error']['code'] ?? 'unknown');
        Log::warning('[WA Cloud] api error', ['salon_id' => $salon->id, 'status' => $durum, 'err' => $hata]);

        return ['ok' => false, 'error' => substr((string) $hata, 0, 150), 'status' => $durum];
    }
}
