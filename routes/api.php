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

});
