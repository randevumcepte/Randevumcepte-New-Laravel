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
    public function baslat(int $oturumId): KopruCevabi
    {
        return $this->istek('post', "/session/{$oturumId}/start");
    }

    public function durum(int $oturumId): KopruCevabi
    {
        return $this->istek('get', "/session/{$oturumId}/status");
    }

    public function qr(int $oturumId): KopruCevabi
    {
        return $this->istek('get', "/session/{$oturumId}/qr");
    }

    public function cikis(int $oturumId): KopruCevabi
    {
        return $this->istek('post', "/session/{$oturumId}/logout");
    }

    public function eslestir(int $oturumId, string $telefon): KopruCevabi
    {
        return $this->istek('post', "/session/{$oturumId}/pair-phone", ['phone' => $telefon]);
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
