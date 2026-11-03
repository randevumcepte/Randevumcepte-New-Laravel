<?php

use App\Http\Controllers\Api\V2\BildirimReklamController;
use Illuminate\Support\Facades\Route;

/*
 | BİLDİRİM REKLAMLARI v2 (yönetim) — routes/api.php korumalı grubundan (auth:isletmeyonetim-api)
 | dahil edilir. Kapsam + yetki controller'da. Yazmalar BILDIRIM_REKLAM_V2_YAZMA_AKTIF arkasında
 | (kapalıyken 409). Web paneli (/v2/bildirim-reklamlari) ve Flutter AYNI uçları kullanır;
 | v1 → v2 eşleme docs/strangler-bildirim-reklam.md. Müşteri uygulaması uçları routes/v2/musteri-uygulama.php.
 */
Route::prefix('bildirim-reklam')->group(function () {
    Route::get('/', [BildirimReklamController::class, 'liste']);
    Route::get('/form-verisi', [BildirimReklamController::class, 'formVerisi']);
    Route::get('/musteri-ara', [BildirimReklamController::class, 'musteriAra']);
    Route::post('/gorsel', [BildirimReklamController::class, 'gorsel']);
    Route::post('/', [BildirimReklamController::class, 'ekle']);

    Route::get('/{id}', [BildirimReklamController::class, 'goster'])->whereNumber('id');
    Route::patch('/{id}', [BildirimReklamController::class, 'guncelle'])->whereNumber('id');
    Route::delete('/{id}', [BildirimReklamController::class, 'sil'])->whereNumber('id');
    Route::patch('/{id}/durum', [BildirimReklamController::class, 'durum'])->whereNumber('id');
    Route::get('/{id}/alici-sayisi', [BildirimReklamController::class, 'aliciSayisi'])->whereNumber('id');
    Route::post('/{id}/gonder', [BildirimReklamController::class, 'gonder'])->whereNumber('id');
});
