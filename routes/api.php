<?php

use App\Http\Controllers\Api\V2\AdisyonController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v2 — Strangler Geçiş Uçları
|--------------------------------------------------------------------------
|
| KVKK / veri koruma ilkesi (ZORUNLU):
|   - KİŞİSEL VERİ içeren HER uç 'auth:isletmeyonetim-api' (legacy-passport)
|     arkasindadir + controller icinde GuvenlikKapisi ile KAPSAM (isletme/sube)
|     + YETKI denetimi yapar. Varsayilan KORUMALI; yeni uclar korumali gruba
|     eklenir. Yalnizca kisisel veri ICERMEYEN uclar (health) public olabilir.
|   - Veri minimizasyonu: Resource/servis yalnizca gereken alanlari dondurur
|     (ham model dokumu YOK). Telefon vb. hassas alan maskelenir (musteri.telefon_gor).
|
| Hem web paneli (Vue) hem Flutter AYNI uclari tuketir; is mantigi servislerde.
|
*/

Route::prefix('v2')->group(function () {

    /*
     | PUBLIC — kisisel veri ICERMEZ (yalnizca servis + DB sagligi).
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
     | KORUMALI — kisisel veri iceren TUM uclar buraya. Kimlik ZORUNLU.
     | Kapsam + yetki her controller'da GuvenlikKapisi ile uygulanir.
     */
    Route::middleware('auth:isletmeyonetim-api')->group(function () {
        Route::get('/adisyon/{id}', [AdisyonController::class, 'goster'])->whereNumber('id');
    });

});
