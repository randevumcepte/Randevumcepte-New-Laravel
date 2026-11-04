<?php

use App\Http\Controllers\Api\V2\MusteriOturumController;
use App\Http\Controllers\Api\V2\VitrinController;
use Illuminate\Support\Facades\Route;

/*
 | KIMLIKSIZ musteri uclari — routes/api.php public bolumunden dahil edilir.
 | Vitrin kisisel veri dondurmez. Oturum uclari throttle + servis icindeki sinirlarla korunur.
 */
Route::prefix('vitrin')->middleware('throttle:120,1')->group(function () {
    Route::get('/coz', [VitrinController::class, 'coz']);
    Route::get('/{salonId}', [VitrinController::class, 'goster'])->whereNumber('salonId');
    Route::get('/{salonId}/yorumlar', [VitrinController::class, 'yorumlar'])->whereNumber('salonId');
    Route::get('/{salonId}/randevu-secenekleri', [VitrinController::class, 'randevuSecenekleri'])->whereNumber('salonId');
    Route::get('/{salonId}/personeller', [VitrinController::class, 'personeller'])->whereNumber('salonId');
    Route::get('/{salonId}/saatler', [VitrinController::class, 'saatler'])->whereNumber('salonId');
});

Route::prefix('musteri-oturum')->middleware('throttle:20,1')->group(function () {
    Route::post('/giris', [MusteriOturumController::class, 'giris']);
    Route::post('/kayit', [MusteriOturumController::class, 'kayit']);
    Route::post('/sifre-kodu', [MusteriOturumController::class, 'sifreKodu']);
    Route::post('/sifre-sifirla', [MusteriOturumController::class, 'sifreSifirla']);
});
