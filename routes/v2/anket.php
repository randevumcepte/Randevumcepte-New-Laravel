<?php

use App\Http\Controllers\Api\V2\AnketAyarController;
use App\Http\Controllers\Api\V2\AnketController;
use App\Http\Controllers\Api\V2\AnketSablonController;
use Illuminate\Support\Facades\Route;

/*
 | MEMNUNİYET ANKETİ v2 — routes/api.php korumalı grubundan (auth:isletmeyonetim-api) dahil edilir.
 | Kapsam + yetki controller'da. Yazmalar ANKET_V2_YAZMA_AKTIF arkasında (kapalıyken 409). Web paneli
 | (/v2/memnuniyet-anketi) ve Flutter AYNI uçları kullanır; v1 → v2 eşleme docs/strangler-memnuniyet-anketi.md.
 | Müşterinin anketi doldurduğu /anket/{token} sayfası eski monolitte kalır.
 */
Route::prefix('anket')->group(function () {
    Route::get('/form-verisi', [AnketController::class, 'formVerisi']);
    Route::get('/ozet', [AnketController::class, 'ozet']);
    Route::post('/hizli-gonder', [AnketController::class, 'hizliGonder']);

    Route::get('/gonderimler', [AnketController::class, 'gonderimListesi']);
    Route::get('/gonderimler/{id}', [AnketController::class, 'gonderimDetay'])->whereNumber('id');
    Route::patch('/gonderimler/{id}/web-gizle', [AnketController::class, 'webGizle'])->whereNumber('id');

    Route::get('/sablonlar', [AnketSablonController::class, 'liste']);
    Route::post('/sablonlar', [AnketSablonController::class, 'ekle']);
    Route::get('/sablonlar/{id}', [AnketSablonController::class, 'goster'])->whereNumber('id');
    Route::patch('/sablonlar/{id}', [AnketSablonController::class, 'guncelle'])->whereNumber('id');
    Route::delete('/sablonlar/{id}', [AnketSablonController::class, 'sil'])->whereNumber('id');
    Route::post('/sablonlar/{id}/gonder', [AnketSablonController::class, 'gonder'])->whereNumber('id');

    Route::get('/ayarlar', [AnketAyarController::class, 'goster']);
    Route::patch('/ayarlar', [AnketAyarController::class, 'kaydet']);
    Route::post('/ayarlar/premium', [AnketAyarController::class, 'premium']);
});
