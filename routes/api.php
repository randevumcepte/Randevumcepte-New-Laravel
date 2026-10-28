<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v2 — Strangler Geçiş Uçları
|--------------------------------------------------------------------------
|
| Bu dosyadaki uçlar hem WEB panelinin (Vue island) hem de FLUTTER mobil
| uygulamasının TEK ortak kaynağıdır. İş mantığı burada DEĞİL,
| `app/Services` altındaki servislerdedir.
|
| Kimlik: eski monolitle paylaşılan Passport token'ı, AppServiceProvider'daki
| 'legacy-passport' guard'i ile doğrulanır. İşletme uçları (tahsilat vb.)
| 'isletmeyonetim-api', müşteri uçları 'api' guard'ını kullanır.
|
*/

Route::prefix('v2')->group(function () {

    /*
     | GET /api/v2/health — auth gerektirmez. Servis + paylaşılan DB sağlığı.
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
     | GET /api/v2/whoami — auth köprüsü duman testi (isletme + musteri token).
     | Hangi guard doğruladıysa onu raporlar. (Faz temizliğinde kaldırılabilir.)
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
                    ],
                    'mesaj' => 'Token dogrulandi',
                ]);
            }
        }

        return response()->json([
            'basarili' => false,
            'mesaj'    => 'Token dogrulanamadi',
        ], 401);
    });

});
