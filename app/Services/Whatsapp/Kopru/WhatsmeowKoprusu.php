<?php

namespace App\Services\Whatsapp\Kopru;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * whatsmeow (Go) köprüsü — eski App\Services\WhatsmeowService ile aynı uçlar ve X-Service-Token.
 * Gövde LOGLANMAZ: QR ve eşleştirme kodu oturum ele geçirmeye yarar.
 */
class WhatsmeowKoprusu implements WhatsappKoprusu
{
    public function baslat(int|string $oturumId): KopruCevabi
    {
        return $this->istek('post', "/session/{$oturumId}/start");
    }

    public function durum(int|string $oturumId): KopruCevabi
    {
        return $this->istek('get', "/session/{$oturumId}/status");
    }

    public function qr(int|string $oturumId): KopruCevabi
    {
        return $this->istek('get', "/session/{$oturumId}/qr");
    }

    public function cikis(int|string $oturumId): KopruCevabi
    {
        return $this->istek('post', "/session/{$oturumId}/logout");
    }

    public function eslestir(int|string $oturumId, string $telefon): KopruCevabi
    {
        return $this->istek('post', "/session/{$oturumId}/pair-phone", ['phone' => $telefon]);
    }

    public function gonder(int|string $oturumId, string $telefon, string $mesaj): KopruCevabi
    {
        return $this->istek('post', "/session/{$oturumId}/send", ['to' => $telefon, 'message' => $mesaj, 'logId' => 0, 'urgent' => false]);
    }

    /** Outbox gönderimi (eski WhatsAppService::sendViaBaileys): gövde çağıranda, kod 202 kuyruğa alındı demektir. */
    public function gonderimIstegi(int|string $oturumId, array $govde): KopruCevabi
    {
        return $this->istek('post', "/session/{$oturumId}/send", $govde);
    }

    private function istek(string $metot, string $yol, array $govde = []): KopruCevabi
    {
        try {
            $cevap = Http::baseUrl(rtrim((string) config('whatsapp.kopru_url'), '/'))
                ->timeout((int) config('whatsapp.zaman_asimi_sn', 10))
                ->withHeaders(['X-Service-Token' => (string) config('whatsapp.servis_token')])
                ->acceptJson()
                ->{$metot}($yol, $govde);

            return new KopruCevabi($cevap->status(), (array) $cevap->json());
        } catch (ConnectionException $e) {
            Log::warning('[v2 whatsapp] kopruye ulasilamadi', ['yol' => $yol, 'hata' => $e->getMessage()]);

            return new KopruCevabi(0);
        }
    }
}
