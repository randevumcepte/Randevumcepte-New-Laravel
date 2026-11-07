<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use GuzzleHttp\Client;

/**
 * FCM v1 HTTP istemcisi: eski NotificationService::sendOne + getFcmAccessToken. Paket gerektirmez; JWT openssl ile uretilir.
 * Guzzle dogrudan kullanilir: gecersiz token karari Guzzle'in hata metnindeki FCM koduna bakar (PushBildirimi), metin eskiyle
 * ayni kalmali. $dosya: config firebase_projects / firebase_bundle_projects degeri; yalniz adi kullanilir.
 * Anahtar icerigi loglanmaz.
 */
class FcmIstemcisi
{
    /** Eskideki gibi surec ici: dosya → erisim belirteci. */
    private static array $belirtecler = [];

    /**
     * @param  float  $zamanAsimi  sn; 0 sinirsiz (eski BildirimController yolu)
     *
     * @throws \Throwable FCM reddederse; cagiran token bakimini metne gore yapar
     */
    public function gonder(string $dosya, array $mesaj, float $zamanAsimi = 10): void
    {
        $hesap = $this->hesap($dosya);
        $url = "https://fcm.googleapis.com/v1/projects/{$hesap['project_id']}/messages:send";

        $cevap = (new Client(['timeout' => $zamanAsimi]))->post($url, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->erisimBelirteci($dosya, $hesap),
                'Content-Type'  => 'application/json',
            ],
            'json'        => ['message' => $mesaj],
            'http_errors' => true,
        ]);

        if ($cevap->getStatusCode() !== 200) {
            throw new \Exception('FCM HTTP '.$cevap->getStatusCode().' - '.$cevap->getBody()->getContents());
        }
    }

    private function hesap(string $dosya): array
    {
        $yol = rtrim((string) config('bildirim_kuyrugu.firebase_anahtar_dizini'), '/').'/'.basename($dosya);
        $hesap = is_file($yol) ? json_decode((string) file_get_contents($yol), true) : null;
        if (! is_array($hesap)) {
            throw new FirebaseAnahtariYok('Firebase anahtar dosyasi okunamadi: '.basename($dosya));
        }

        return $hesap;
    }

    private function erisimBelirteci(string $dosya, array $hesap): string
    {
        if (isset(self::$belirtecler[$dosya]) && self::$belirtecler[$dosya]['exp'] > time() + 60) {
            return self::$belirtecler[$dosya]['token'];
        }

        $simdi = time();
        $b64 = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $imzalanan = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
            'iss'   => $hesap['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $hesap['token_uri'],
            'iat'   => $simdi,
            'exp'   => $simdi + 3600,
        ]));
        openssl_sign($imzalanan, $imza, $hesap['private_key'], OPENSSL_ALGO_SHA256);

        $cevap = (new Client(['timeout' => 10]))->post($hesap['token_uri'], [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $imzalanan.'.'.$b64($imza),
            ],
        ]);
        $govde = json_decode($cevap->getBody()->getContents(), true);
        self::$belirtecler[$dosya] = ['token' => $govde['access_token'], 'exp' => $simdi + 3550];

        return $govde['access_token'];
    }
}
