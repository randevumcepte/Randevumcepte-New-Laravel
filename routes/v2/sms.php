<?php

use App\Http\Controllers\Api\V2\SmsAyarController;
use App\Http\Controllers\Api\V2\SmsController;
use App\Http\Controllers\Api\V2\SmsGonderimController;
use App\Http\Controllers\Api\V2\SmsKaraListeController;
use App\Http\Controllers\Api\V2\SmsSablonController;
use Illuminate\Support\Facades\Route;

/*
 | SMS YÖNETİMİ v2 — routes/api.php korumalı grubundan (auth:isletmeyonetim-api) dahil edilir. Kapsam +
 | yetki SmsTabanController'da; yazmalar SMS_V2_YAZMA_AKTIF arkasında (kapalıyken 409). Web
 | (/v2/sms-yonetimi) ve Flutter AYNI uçları kullanır; v1 → v2 eşleme docs/strangler-sms-yonetimi.md.
 */
Route::prefix('sms')->group(function () {
    Route::get('/acilis', [SmsController::class, 'acilis']);
    Route::get('/bakiye', [SmsController::class, 'bakiye']);
    Route::get('/raporlar', [SmsController::class, 'raporlar']);
    Route::get('/raporlar/{paket}', [SmsController::class, 'raporDetay'])->where('paket', '[A-Za-z0-9_-]+');

    Route::get('/alicilar', [SmsGonderimController::class, 'alicilar']);
    Route::get('/alicilar/idler', [SmsGonderimController::class, 'aliciIdleri']);
    Route::get('/musteri-ara', [SmsGonderimController::class, 'musteriAra']);
    Route::post('/gonderim/onizle', [SmsGonderimController::class, 'onizle']);
    Route::post('/gonderim', [SmsGonderimController::class, 'gonder']);
    Route::get('/gonderim/{id}', [SmsGonderimController::class, 'durum'])->whereNumber('id');

    Route::get('/sablonlar', [SmsSablonController::class, 'liste']);
    Route::post('/sablonlar', [SmsSablonController::class, 'ekle']);
    Route::patch('/sablonlar/{id}', [SmsSablonController::class, 'guncelle'])->whereNumber('id');
    Route::delete('/sablonlar/{id}', [SmsSablonController::class, 'sil'])->whereNumber('id');

    Route::get('/ayarlar', [SmsAyarController::class, 'goster']);
    Route::patch('/ayarlar', [SmsAyarController::class, 'kaydet']);

    Route::get('/kara-liste', [SmsKaraListeController::class, 'liste']);
    Route::post('/kara-liste', [SmsKaraListeController::class, 'ekle']);
    Route::delete('/kara-liste/{userId}', [SmsKaraListeController::class, 'sil'])->whereNumber('userId');
});
