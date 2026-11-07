<?php

use App\Http\Controllers\Api\V2\PaketController;
use Illuminate\Support\Facades\Route;

/*
 | PAKET YONETIMI v2 — routes/api.php korumali grubundan (auth:isletmeyonetim-api)
 | dahil edilir. Kapsam + yetki controller'da (PaketController). Yazmalar
 | PAKET_V2_YAZMA_AKTIF arkasinda (kapaliyken 409). Web paneli (/v2/paketler) ve
 | Flutter AYNI uclari kullanir; v1 → v2 esleme docs/strangler-paket.md.
 */
Route::prefix('paket')->group(function () {
    Route::get('/', [PaketController::class, 'liste']);
    Route::get('/form-verisi', [PaketController::class, 'formVerisi']);
    Route::get('/musteri-ara', [PaketController::class, 'musteriAra']);
    Route::get('/musteri-kontrol', [PaketController::class, 'musteriKontrol']);
    Route::post('/', [PaketController::class, 'ekle']);
    Route::post('/satis', [PaketController::class, 'sat']);

    Route::get('/{id}', [PaketController::class, 'goster'])->whereNumber('id');
    Route::patch('/{id}', [PaketController::class, 'guncelle'])->whereNumber('id');
    Route::delete('/{id}', [PaketController::class, 'sil'])->whereNumber('id');
});
