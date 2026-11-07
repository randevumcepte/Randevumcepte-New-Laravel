<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use Illuminate\Support\Facades\Http;

/**
 * Efetech uclari, eski curl cagrilariyla ayni: 5 sn zaman asimi, HTTP kodu bakilmaz, ulasilamazsa null. Mesaj dizisi
 * oldugu gibi gider (user_id, wa_message gibi ek alanlar dahil; eskide de boyleydi).
 */
class EfetechIstemcisi
{
    private const ZAMAN_ASIMI_SN = 5;

    public function gonder(string $apiKey, ?string $gonderen, array $mesajlar): ?array
    {
        return $this->istek((string) config('sms.efetech.gonderim_url'), $apiKey, ['originator' => $gonderen, 'messages' => $mesajlar, 'encoding' => 'auto']);
    }

    public function rapor(object $salon, mixed $raporId): ?array
    {
        return $this->istek((string) config('sms.efetech.rapor_url'), (string) $salon->sms_apikey, ['originator' => $salon->sms_baslik, 'id' => $raporId]);
    }

    private function istek(string $url, string $apiKey, array $govde): ?array
    {
        try {
            $cevap = Http::timeout(self::ZAMAN_ASIMI_SN)->acceptJson()
                ->withHeaders(['Authorization' => 'Key '.$apiKey])
                ->post($url, $govde)
                ->json();
        } catch (\Throwable) {
            return null;
        }

        return is_array($cevap) ? $cevap : null;
    }
}
