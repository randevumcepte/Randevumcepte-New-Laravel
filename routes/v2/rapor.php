<?php

use App\Http\Controllers\Api\V2\IsletmeRaporController;
use App\Http\Controllers\Api\V2\KasaController;
use App\Http\Controllers\Api\V2\MasrafController;
use App\Http\Controllers\Api\V2\PrimController;
use App\Http\Controllers\Api\V2\RaporController;
use Illuminate\Support\Facades\Route;

/*
 | RAPORLAR + KASA v2 — routes/api.php korumali grubundan (auth:isletmeyonetim-api) dahil
 | edilir. Kapsam + yetki her controller'da (RaporTabanController / V2Controller).
 | Donem sozlesmesi tum uclarda ayni: donem=bugun|dun|hafta|ay|gecen_ay|yil|gecen_yil|ozel
 | (+ baslangic/bitis Y-m-d). Web (/v2/raporlar, /v2/isletme-raporlari, /v2/kasa) ve Flutter
 | AYNI uclari kullanir; v1 → v2 esleme docs/strangler-rapor-kasa.md.
 */
Route::prefix('rapor')->group(function () {
    Route::get('/secenekler', [RaporController::class, 'secenekler']);

    Route::get('/satis', [RaporController::class, 'kalemler']);
    Route::get('/satis/personel', [RaporController::class, 'personeller']);
    Route::get('/satis/{tur}/{kalem}/musteriler', [RaporController::class, 'musteriler'])
        ->whereIn('tur', ['hizmet', 'urun', 'paket', 'seans'])->whereNumber('kalem');

    Route::prefix('isletme')->group(function () {
        foreach (['ozet', 'hizmet', 'urun', 'personel', 'musteri', 'randevu'] as $sekme) {
            Route::get('/'.$sekme, [IsletmeRaporController::class, $sekme]);
        }
    });
});

Route::prefix('kasa')->group(function () {
    Route::get('/ozet', [KasaController::class, 'ozet']);
    Route::get('/gelirler', [KasaController::class, 'gelirler']);
    Route::get('/giderler', [KasaController::class, 'giderler']);
    Route::get('/gelir/{id}', [KasaController::class, 'gelirDetay'])->whereNumber('id');
    Route::get('/gun-sonu', [KasaController::class, 'gunSonu']);
    Route::get('/aylik', [KasaController::class, 'aylik']);

    // YAZMA — KASA_V2_YAZMA_AKTIF kapaliyken 409.
    Route::post('/para-giris', [KasaController::class, 'paraGiris']);
    Route::post('/para-cikis/onay-kodu', [KasaController::class, 'paraCikisKodu']);
    Route::post('/para-cikis', [KasaController::class, 'paraCikis']);
    Route::delete('/gelir/{id}', [KasaController::class, 'gelirSil'])->whereNumber('id');
});

Route::prefix('masraf')->group(function () {
    Route::get('/form-verisi', [MasrafController::class, 'formVerisi']);
    Route::get('/kategoriler', [MasrafController::class, 'kategoriler']);
    Route::get('/{id}', [MasrafController::class, 'goster'])->whereNumber('id');
    Route::post('/', [MasrafController::class, 'ekle']);
    Route::patch('/{id}', [MasrafController::class, 'guncelle'])->whereNumber('id');
    Route::delete('/{id}', [MasrafController::class, 'sil'])->whereNumber('id');
});

Route::prefix('prim')->group(function () {
    Route::get('/hakedis', [PrimController::class, 'hakedis']);
    Route::get('/personel/{id}', [PrimController::class, 'personel'])->whereNumber('id');
    Route::get('/odemeler', [PrimController::class, 'odemeler']);

    // YAZMA — KASA_V2_YAZMA_AKTIF kapaliyken 409.
    Route::post('/odeme', [PrimController::class, 'ode']);
    Route::delete('/odeme/{id}', [PrimController::class, 'odemeSil'])->whereNumber('id');
    Route::post('/hareket', [PrimController::class, 'hareketEkle']);
    Route::delete('/hareket/{id}', [PrimController::class, 'hareketSil'])->whereNumber('id');
});
