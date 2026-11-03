<?php

use App\Http\Controllers\Api\V2\UygulamaAfisiController;
use Illuminate\Support\Facades\Route;

/*
 | UYGULAMA İNDİRME AFİŞİ v2 (SALT OKUMA) — routes/api.php korumalı grubundan
 | (auth:isletmeyonetim-api) dahil edilir. Kapsam + "afiş hazır" kuralı controller'da.
 | Web (/v2/uygulama-afisi) ve Flutter AYNI uçları kullanır; görseller Bearer ile blob alınır.
 */
Route::prefix('uygulama-afisi')->group(function () {
    Route::get('/', [UygulamaAfisiController::class, 'ozet']);
    Route::get('/onizleme', [UygulamaAfisiController::class, 'onizleme']);
    Route::get('/pdf', [UygulamaAfisiController::class, 'pdf']);
});
