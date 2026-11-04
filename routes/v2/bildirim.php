<?php

use App\Http\Controllers\Api\V2\BildirimController;
use Illuminate\Support\Facades\Route;

/*
 | BİLDİRİMLER v2 — routes/api.php korumalı grubundan (auth:isletmeyonetim-api) dahil edilir.
 | Web panel zili ve Flutter yönetici AYNI uçları kullanır. Gönderim v2'de değil: outbox → eski FCM kodu.
 */
Route::prefix('bildirim')->group(function () {
    Route::get('/', [BildirimController::class, 'liste']);
    Route::delete('/', [BildirimController::class, 'tumunuSil']);
    Route::get('/sayac', [BildirimController::class, 'sayac']);
    Route::get('/web-ayar', [BildirimController::class, 'webAyar']);
    Route::post('/cihaz', [BildirimController::class, 'cihazKaydet']);
    Route::delete('/cihaz', [BildirimController::class, 'cihazSil']);
    Route::post('/tumu/okundu', [BildirimController::class, 'tumunuOku']);
    Route::post('/{id}/okundu', [BildirimController::class, 'okundu'])->whereNumber('id');
    Route::delete('/{id}', [BildirimController::class, 'sil'])->whereNumber('id');
});
