<?php

use App\Http\Controllers\Api\V2\DenemeController;
use App\Http\Controllers\Api\V2\OturumController;
use Illuminate\Support\Facades\Route;

/*
 | Giris, sifre sifirlama, ucretsiz deneme — web + Flutter tek uc. Kimliksiz uclar throttle + servis
 | icindeki sinirlarla korunur; hesap varligi yalniz deneme kaydinda (zorunlu olarak) gorunur.
 */
Route::prefix('oturum')->group(function () {
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/giris', [OturumController::class, 'giris']);
        Route::post('/sifre-kodu', [OturumController::class, 'sifreKodu']);
        Route::post('/sifre-sifirla', [OturumController::class, 'sifreSifirla']);
    });
    Route::post('/cikis', [OturumController::class, 'cikis'])->middleware('auth:isletmeyonetim-api,api');
});

Route::prefix('deneme')->group(function () {
    Route::get('/secenekler', [DenemeController::class, 'secenekler'])->middleware('throttle:60,1');
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/kod-gonder', [DenemeController::class, 'kodGonder']);
        Route::post('/dogrula', [DenemeController::class, 'dogrulaVeKur']);
    });
});
