<?php

use App\Http\Controllers\Api\V2\AdisyonController;
use App\Http\Controllers\Api\V2\KabukController;
use App\Http\Controllers\Api\V2\KatalogController;
use App\Http\Controllers\Api\V2\SatisController;
use App\Http\Controllers\Api\V2\SatisKalemController;
use App\Http\Controllers\Api\V2\TahsilatController;
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
        // Panel kabugu (sol menu + header verisi) — v2 ekranlarinin ortak shell'i.
        Route::get('/kabuk', [KabukController::class, 'goster']);
        Route::get('/adisyon/{id}', [AdisyonController::class, 'goster'])->whereNumber('id');
        Route::get('/satislar', [SatisController::class, 'index']);
        Route::get('/katalog', [KatalogController::class, 'index']);
        // YAZMA — su an yalnizca DRY-RUN (controller gercek yazmayi reddeder).
        Route::post('/tahsilat', [TahsilatController::class, 'ekle']);
        // IADE/SILME — flag korumali (satis.tahsilat_sil).
        Route::delete('/tahsilat/{id}', [TahsilatController::class, 'sil'])->whereNumber('id');

        // SATIS KALEM yonetimi (Satis & Tahsilat Duzenleme) — flag korumali.
        Route::post('/adisyon/{adisyon}/kalem', [SatisKalemController::class, 'ekle'])->whereNumber('adisyon');
        Route::patch('/kalem/urun/{id}', [SatisKalemController::class, 'urunAdet'])->whereNumber('id');
        Route::delete('/kalem/{tip}/{id}', [SatisKalemController::class, 'sil'])->where('tip', 'hizmet|urun|paket')->whereNumber('id');
    });

});
