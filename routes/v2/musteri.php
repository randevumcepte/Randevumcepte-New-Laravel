<?php

use App\Http\Controllers\Api\V2\MusteriController;
use App\Http\Controllers\Api\V2\MusteriOlcumController;
use App\Http\Controllers\Api\V2\MusteriTelefonKontrolController;
use Illuminate\Support\Facades\Route;

/*
 | MUSTERI YONETIMI v2 — routes/api.php korumali grubundan (auth:isletmeyonetim-api)
 | dahil edilir. Kapsam + yetki + portfoy her controller'da (MusteriTabanController).
 | Yazmalar MUSTERI_V2_YAZMA_AKTIF arkasinda (kapaliyken 409). Web paneli
 | (/v2/musteriler, /v2/musteri/{id}) ve Flutter AYNI uclari kullanir; v1 esleme
 | tablosu docs/flutter-uyum-yol-haritasi.md.
 */
Route::prefix('musteri')->group(function () {
    Route::get('/', [MusteriController::class, 'liste']);
    Route::get('/sayilar', [MusteriController::class, 'sayilar']);
    Route::get('/ara', [MusteriController::class, 'ara']);
    Route::get('/form-verisi', [MusteriController::class, 'formVerisi']);
    Route::post('/', [MusteriController::class, 'kaydet']);

    Route::post('/telefon-kontrol', [MusteriTelefonKontrolController::class, 'kontrol'])->middleware('throttle:30,1');
    Route::delete('/olcum/{olcumId}', [MusteriOlcumController::class, 'sil'])->whereNumber('olcumId');

    Route::prefix('{id}')->whereNumber('id')->group(function () {
        Route::get('/', [MusteriController::class, 'goster']);
        Route::patch('/', [MusteriController::class, 'kaydet']);
        Route::delete('/', [MusteriController::class, 'sil']);
        Route::get('/randevu-notlari', [MusteriController::class, 'randevuNotlari']);
        Route::get('/randevular', [MusteriController::class, 'randevular']);
        Route::patch('/kara-liste', [MusteriController::class, 'karaListe']);
        Route::patch('/saglik', [MusteriController::class, 'saglik']);
        Route::get('/olcumler', [MusteriOlcumController::class, 'liste']);
        Route::post('/olcumler', [MusteriOlcumController::class, 'ekle']);
    });
});
