<?php

use App\Http\Controllers\Api\V2\CarkController;
use App\Http\Controllers\Api\V2\CarkKuponController;
use App\Http\Controllers\Api\V2\SadakatController;
use Illuminate\Support\Facades\Route;

/*
 | ÇARKIFELEK + SADAKAT v2 — routes/api.php korumalı grubundan (auth:isletmeyonetim-api) dahil edilir.
 | Kapsam + yetki controller'larda. Yazmalar CARK_V2_YAZMA_AKTIF arkasında (kapalıyken 409).
 | Web (/v2/carkifelek) ve Flutter AYNI uçları kullanır; v1 → v2 eşleme docs/strangler-carkifelek.md.
 */
Route::prefix('cark')->group(function () {
    Route::get('/', [CarkController::class, 'acilis']);
    Route::put('/', [CarkController::class, 'kaydet']);
    Route::patch('/aktif', [CarkController::class, 'aktiflik']);
    Route::post('/duyuru', [CarkController::class, 'duyuru']);
    Route::get('/hatirlatma', [CarkController::class, 'hatirlatma']);
    Route::put('/hatirlatma', [CarkController::class, 'hatirlatmaKaydet']);

    Route::get('/ozet', [CarkKuponController::class, 'ozet']);
    Route::get('/kuponlar', [CarkKuponController::class, 'liste']);
    Route::get('/cevirmeler', [CarkKuponController::class, 'cevirmeler']);
    Route::get('/musteri-kuponlari', [CarkKuponController::class, 'musteriKuponlari']);
    Route::post('/kupon/dogrula', [CarkKuponController::class, 'dogrula']);
    Route::post('/kupon/{id}/kullan', [CarkKuponController::class, 'kullan'])->whereNumber('id');
    Route::post('/kupon/{id}/geri-al', [CarkKuponController::class, 'geriAl'])->whereNumber('id');
});

Route::prefix('sadakat')->group(function () {
    Route::get('/', [SadakatController::class, 'goster']);
    Route::put('/kazanim', [SadakatController::class, 'kazanimKaydet']);
    Route::post('/puan-odulu', [SadakatController::class, 'odulEkle']);
    Route::post('/puan-odulu/ornekler', [SadakatController::class, 'ornekler']);
    Route::patch('/puan-odulu/{id}', [SadakatController::class, 'odulGuncelle'])->whereNumber('id');
    Route::delete('/puan-odulu/{id}', [SadakatController::class, 'odulSil'])->whereNumber('id');
});
