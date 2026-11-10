<?php

use App\Http\Controllers\Api\V2\MusteriOturumController;
use App\Http\Controllers\Api\V2\VitrinCarkController;
use App\Http\Controllers\Api\V2\VitrinController;
use Illuminate\Support\Facades\Route;

/*
 | KIMLIKSIZ musteri uclari — routes/api.php public bolumunden dahil edilir.
 | Vitrin kisisel veri dondurmez. Oturum uclari throttle + servis icindeki sinirlarla korunur.
 */
Route::prefix('vitrin')->middleware('throttle:120,1')->group(function () {
    Route::get('/coz', [VitrinController::class, 'coz']);
    Route::get('/marka', [VitrinController::class, 'marka']);
    Route::get('/surum', [VitrinController::class, 'surum']);
    Route::get('/marka-yorumlari', [VitrinController::class, 'markaYorumlari']);
    Route::get('/{salonId}', [VitrinController::class, 'goster'])->whereNumber('salonId');
    Route::get('/{salonId}/yorumlar', [VitrinController::class, 'yorumlar'])->whereNumber('salonId');
    Route::get('/{salonId}/randevu-secenekleri', [VitrinController::class, 'randevuSecenekleri'])->whereNumber('salonId');
    Route::get('/{salonId}/personeller', [VitrinController::class, 'personeller'])->whereNumber('salonId');
    Route::get('/{salonId}/saatler', [VitrinController::class, 'saatler'])->whereNumber('salonId');

    // Hediye carki, misafir yolu (cark verisi GET /vitrin/{salonId} 'cark' alaninda). Ayri onekli siki sinir; servis
    // ayrica IP, numara ve kod sinirlari uygular. Giris yapmis musteri /musteri-uygulama/cark uclarini kullanir.
    Route::prefix('/{salonId}/cark')->whereNumber('salonId')->middleware('throttle:10,1,vitrin-cark')->group(function () {
        Route::post('/cevir', [VitrinCarkController::class, 'cevir']);
        Route::post('/kod', [VitrinCarkController::class, 'kodGonder']);
        Route::post('/dogrula', [VitrinCarkController::class, 'kodDogrula']);
    });
});

Route::prefix('musteri-oturum')->middleware('throttle:20,1')->group(function () {
    Route::post('/giris', [MusteriOturumController::class, 'giris']);
    Route::post('/kayit', [MusteriOturumController::class, 'kayit']);
    Route::post('/sifre-kodu', [MusteriOturumController::class, 'sifreKodu']);
    Route::post('/sifre-sifirla', [MusteriOturumController::class, 'sifreSifirla']);
    Route::post('/sifre-gonder', [MusteriOturumController::class, 'sifreGonder']);
    Route::post('/yenile', [MusteriOturumController::class, 'yenile']);
});
