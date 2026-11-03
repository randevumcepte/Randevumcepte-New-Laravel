<?php

use App\Http\Controllers\Api\V2\KampanyaController;
use App\Http\Controllers\Api\V2\KampanyaKatilimciController;
use App\Http\Controllers\Api\V2\KampanyaSenaryoController;
use Illuminate\Support\Facades\Route;

/*
 | REKLAM YÖNETİMİ v2 (kampanya + rapor + senaryo sihirbazı) — routes/api.php korumalı grubundan
 | (auth:isletmeyonetim-api) dahil edilir. Kapsam + yetki (pazarlama.kampanya_yonet) controller'da;
 | yazmalar KAMPANYA_V2_YAZMA_AKTIF arkasında (kapalıyken 409). Web (/v2/reklam-yonetimi) ve Flutter
 | AYNI uçları kullanır; v1 → v2 eşleme docs/strangler-reklam-yonetimi.md.
 */
Route::prefix('kampanya')->group(function () {
    Route::get('/form-verisi', [KampanyaController::class, 'formVerisi']);
    Route::get('/musteri-ara', [KampanyaController::class, 'musteriAra']);
    Route::post('/kitle-sayisi', [KampanyaController::class, 'kitleSayisi']);
    Route::post('/mesaj-onizle', [KampanyaController::class, 'mesajOnizle']);

    Route::get('/senaryo', [KampanyaSenaryoController::class, 'liste']);
    Route::post('/senaryo', [KampanyaSenaryoController::class, 'ekle']);
    Route::post('/senaryo/onizle', [KampanyaSenaryoController::class, 'onizle']);
    Route::get('/senaryo/{id}', [KampanyaSenaryoController::class, 'goster'])->whereNumber('id');
    Route::patch('/senaryo/{id}', [KampanyaSenaryoController::class, 'guncelle'])->whereNumber('id');
    Route::delete('/senaryo/{id}', [KampanyaSenaryoController::class, 'sil'])->whereNumber('id');

    Route::get('/', [KampanyaController::class, 'liste']);
    Route::post('/', [KampanyaController::class, 'ekle']);
    Route::get('/{id}', [KampanyaController::class, 'goster'])->whereNumber('id');
    Route::patch('/{id}', [KampanyaController::class, 'guncelle'])->whereNumber('id');
    Route::delete('/{id}', [KampanyaController::class, 'sil'])->whereNumber('id');

    Route::get('/{id}/katilimcilar', [KampanyaKatilimciController::class, 'liste'])->whereNumber('id');
    Route::post('/{id}/katilimcilar', [KampanyaKatilimciController::class, 'ekle'])->whereNumber('id');
    Route::delete('/{id}/katilimcilar/{kid}', [KampanyaKatilimciController::class, 'sil'])->whereNumber(['id', 'kid']);
    Route::post('/{id}/tekrar-ara', [KampanyaKatilimciController::class, 'tekrarAra'])->whereNumber('id');
    Route::post('/{id}/personele-ata', [KampanyaKatilimciController::class, 'personeleAta'])->whereNumber('id');
});
