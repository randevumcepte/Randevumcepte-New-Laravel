<?php

use App\Http\Controllers\Api\V2\AyarController;
use App\Http\Controllers\Api\V2\CihazController;
use App\Http\Controllers\Api\V2\IsletmeGorselController;
use App\Http\Controllers\Api\V2\OdaController;
use App\Http\Controllers\Api\V2\RandevuAyarController;
use Illuminate\Support\Facades\Route;

/*
 | AYARLAR v2 — routes/api.php korumali grubundan (auth:isletmeyonetim-api) dahil edilir.
 | Kapsam + sekme yetkisi (AyarSozluk) controller'da; yazmalar AYAR_V2_YAZMA_AKTIF arkasinda
 | (kapaliyken 409). Web paneli (/v2/ayarlar) ve Flutter AYNI uclari kullanir; v1 esleme
 | tablosu docs/flutter-uyum-yol-haritasi.md. Okumalar salon_id query, yazmalar govdede.
 */
Route::prefix('ayar')->group(function () {
    Route::get('/ozet', [AyarController::class, 'ozet']);

    Route::get('/isletme', [AyarController::class, 'isletme']);
    Route::patch('/isletme', [AyarController::class, 'isletmeKaydet']);
    Route::post('/isletme/logo', [IsletmeGorselController::class, 'logo']);
    Route::get('/isletme/gorseller', [IsletmeGorselController::class, 'galeri']);
    Route::post('/isletme/gorseller', [IsletmeGorselController::class, 'galeriEkle']);
    Route::delete('/isletme/gorseller/{id}', [IsletmeGorselController::class, 'galeriSil'])->whereNumber('id');

    Route::get('/calisma-saatleri', [AyarController::class, 'calismaSaatleri']);
    Route::put('/calisma-saatleri', [AyarController::class, 'calismaSaatleriKaydet']);

    Route::get('/randevu', [RandevuAyarController::class, 'randevu']);
    Route::patch('/randevu', [RandevuAyarController::class, 'randevuKaydet']);
    Route::get('/online-saatler', [RandevuAyarController::class, 'onlineSaatler']);
    Route::put('/online-saatler', [RandevuAyarController::class, 'onlineSaatKaydet']);

    Route::get('/musteri-indirimleri', [AyarController::class, 'musteriIndirimleri']);
    Route::put('/musteri-indirimleri', [AyarController::class, 'musteriIndirimKaydet']);

    Route::get('/tema', [AyarController::class, 'tema']);
    Route::put('/tema', [AyarController::class, 'temaKaydet']);

    // Takvim kaynaklari — cihaz ve oda AYNI uc kumesi (KaynakTabanController); kayit govdesi ture ozel.
    foreach (['cihazlar' => CihazController::class, 'odalar' => OdaController::class] as $yol => $controller) {
        Route::prefix($yol)->group(function () use ($controller) {
            Route::get('/', [$controller, 'liste']);
            Route::get('/form-verisi', [$controller, 'formVerisi']);
            Route::post('/', [$controller, 'kaydet']);
            Route::prefix('{id}')->whereNumber('id')->group(function () use ($controller) {
                Route::get('/', [$controller, 'goster']);
                Route::patch('/', [$controller, 'kaydet']);
                Route::delete('/', [$controller, 'sil']);
                Route::patch('/durum', [$controller, 'durum']);
                Route::patch('/sira', [$controller, 'sira']);
            });
        });
    }
});
