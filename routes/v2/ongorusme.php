<?php

use App\Http\Controllers\Api\V2\OnGorusmeController;
use Illuminate\Support\Facades\Route;

/*
 | ÖN GÖRÜŞME v2 — routes/api.php korumalı grubundan (auth:isletmeyonetim-api) dahil edilir.
 | Kapsam + yetki controller'da (OnGorusmeController). Yazmalar ONGORUSME_V2_YAZMA_AKTIF
 | arkasında (kapalıyken 409). Web paneli (/v2/ongorusmeler) ve Flutter AYNI uçları kullanır;
 | v1 → v2 eşleme docs/strangler-ongorusme.md.
 */
Route::prefix('ongorusme')->group(function () {
    Route::get('/', [OnGorusmeController::class, 'liste']);
    Route::get('/form-verisi', [OnGorusmeController::class, 'formVerisi']);
    Route::get('/musteri-ara', [OnGorusmeController::class, 'musteriAra']);
    Route::post('/', [OnGorusmeController::class, 'ekle']);

    Route::get('/{id}', [OnGorusmeController::class, 'goster'])->whereNumber('id');
    Route::patch('/{id}', [OnGorusmeController::class, 'guncelle'])->whereNumber('id');
    Route::post('/{id}/satis-yapildi', [OnGorusmeController::class, 'satisYapildi'])->whereNumber('id');
    Route::post('/{id}/satis-yapilmadi', [OnGorusmeController::class, 'satisYapilmadi'])->whereNumber('id');
});
