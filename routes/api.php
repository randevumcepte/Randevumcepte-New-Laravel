<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v2 — Strangler Geçiş Uçları
|--------------------------------------------------------------------------
|
| Bu dosyadaki uçlar hem WEB panelinin (Vue island) hem de FLUTTER mobil
| uygulamasının TEK ortak kaynağıdır. İlke: iş mantığı burada DEĞİL,
| `app/Services` altındaki servislerdedir. Buradaki tanımlar yalnızca
| yönlendirme + istek doğrulama + Resource'a devretme yapar. Böylece aynı
| mantık web ve mobil için ikiye bölünmez (reusable, kod tekrarsız).
|
| Kimlik doğrulama (Laravel Passport, eski monolitle paylaşılan
| oauth_access_tokens + ortak public key) ayrı bir alt-fazda eklenecek.
| Şimdilik sadece auth GEREKTİRMEYEN sağlık ucu var.
|
*/

Route::prefix('v2')->group(function () {

    /*
     | GET /api/v2/health
     | Yeni servisin ayakta olduğunu ve paylaşılan MySQL'e bağlanabildiğini
     | doğrular. Auth gerektirmez. Deploy/izleme için kullanılır.
     */
    Route::get('/health', function () {
        $veritabani = 'down';

        try {
            DB::connection()->getPdo();
            $veritabani = 'up';
        } catch (\Throwable $e) {
            $veritabani = 'error';
        }

        return response()->json([
            'servis'     => 'randevumcepte-v2',
            'durum'      => $veritabani === 'up' ? 'ok' : 'degraded',
            'veritabani' => $veritabani,
            'surum'      => 'v2',
        ], $veritabani === 'up' ? 200 : 503);
    });

    /*
     | GET /api/v2/whoami
     | GEÇİCİ TEST UCU — Passport 4 ile üretilmiş eski token'ın Passport 13
     | tarafından doğrulanıp doğrulanmadığını kanıtlar. Token hangi kullanıcı
     | tipine aitse (musteri=api / isletme=isletmeyonetim-api) o guard doğrular;
     | bu uç ikisini de dener ve hangisinin tuttuğunu raporlar.
     | Make-or-break testi geçince bu uç kaldırılacak.
     */
    Route::get('/whoami', function (\Illuminate\Http\Request $request) {
        foreach (['isletmeyonetim-api', 'api'] as $guard) {
            $user = auth($guard)->user();

            if ($user) {
                return response()->json([
                    'basarili' => true,
                    'veri' => [
                        'guard' => $guard,
                        'id'    => $user->getAuthIdentifier(),
                        'tablo' => $user->getTable(),
                        'ornek' => collect($user->getAttributes())
                            ->only(['id', 'ad', 'adi', 'isim', 'ad_soyad', 'name', 'gsm1', 'cep_telefon', 'email'])
                            ->toArray(),
                    ],
                    'mesaj' => 'Token dogrulandi',
                ]);
            }
        }

        return response()->json([
            'basarili' => false,
            'mesaj'    => 'Token hicbir guard ile dogrulanamadi (isletmeyonetim-api, api)',
        ], 401);
    });

    /*
     | GET /api/v2/token-debug  — GEÇİCİ TEŞHİS
     | JWT'yi elle çözer, imzayı storage/oauth-public.key ile doğrular ve
     | oauth_access_tokens / oauth_clients kayıtlarını kontrol eder. Hangi
     | adimda patladigini (imza mi, DB kaydi mi) net gosterir.
     */
    Route::get('/token-debug', function (\Illuminate\Http\Request $request) {
        $jwt = $request->bearerToken();
        if (! $jwt) {
            return response()->json(['hata' => 'Bearer token yok'], 400);
        }

        $parcalar = explode('.', $jwt);
        if (count($parcalar) !== 3) {
            return response()->json(['hata' => 'JWT 3 parcali degil', 'parca_sayisi' => count($parcalar)], 400);
        }

        $b64url = fn ($d) => base64_decode(strtr($d, '-_', '+/'));
        $header  = json_decode($b64url($parcalar[0]), true);
        $payload = json_decode($b64url($parcalar[1]), true);
        $imza    = $b64url($parcalar[2]);

        // 1) Imza dogrulama (RS256) — kopyalanan public key ile
        $pubPath = storage_path('oauth-public.key');
        $pub     = is_file($pubPath) ? file_get_contents($pubPath) : null;
        $imzaSonuc = 'public-key-yok';
        if ($pub) {
            $res = openssl_verify($parcalar[0].'.'.$parcalar[1], $imza, $pub, OPENSSL_ALGO_SHA256);
            $imzaSonuc = $res === 1 ? 'GECERLI' : ($res === 0 ? 'GECERSIZ (imza tutmuyor)' : 'HATA: '.openssl_error_string());
        }

        // 2) DB kayitlari
        $jti = $payload['jti'] ?? null;
        $aud = $payload['aud'] ?? null;
        $tokenRow = $client = null;
        $dbHata = null;
        try {
            $tokenRow = $jti ? \DB::table('oauth_access_tokens')->where('id', $jti)->first() : null;
            $client   = $aud ? \DB::table('oauth_clients')->where('id', $aud)->first() : null;
        } catch (\Throwable $e) {
            $dbHata = $e->getMessage();
        }

        // 3) league/oauth2-server (Passport'un kullandigi) dogrulayicisini DOGRUDAN
        //    calistirip firlatan exception'i yakala — asil red sebebi burada.
        $leagueSonuc = null;
        try {
            $guzzle = new \GuzzleHttp\Psr7\HttpFactory();
            $psrFactory = new \Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory($guzzle, $guzzle, $guzzle, $guzzle);
            $psr = $psrFactory->createRequest($request);
            app(\League\OAuth2\Server\ResourceServer::class)->validateAuthenticatedRequest($psr);
            $leagueSonuc = 'GECERLI';
        } catch (\Throwable $e) {
            $leagueSonuc = get_class($e)
                . ' | ' . $e->getMessage()
                . ' | hint=' . (method_exists($e, 'getHint') ? $e->getHint() : '-')
                . ' | prev=' . ($e->getPrevious() ? get_class($e->getPrevious()).': '.$e->getPrevious()->getMessage() : '-');
        }

        return response()->json([
            'header'  => $header,
            'payload' => $payload,
            'imza_dogrulama' => $imzaSonuc,
            'league_dogrulama' => $leagueSonuc,
            'public_key_var' => (bool) $pub,
            'oauth_access_tokens' => $tokenRow ? [
                'bulundu' => true,
                'revoked' => $tokenRow->revoked ?? null,
                'user_id' => $tokenRow->user_id ?? null,
                'client_id' => $tokenRow->client_id ?? null,
                'expires_at' => $tokenRow->expires_at ?? null,
            ] : ['bulundu' => false],
            'oauth_clients' => $client ? [
                'bulundu' => true,
                'revoked' => $client->revoked ?? null,
                'name' => $client->name ?? null,
            ] : ['bulundu' => false],
            'db_hata' => $dbHata,
        ]);
    });

});
