<?php

use App\Http\Controllers\Api\V2\StokController;
use App\Http\Controllers\Api\V2\StokIslemController;
use App\Http\Controllers\Api\V2\StokTanimController;
use App\Http\Controllers\Api\V2\StokUrunController;
use Illuminate\Support\Facades\Route;

/*
 | STOK YONETIMI v2 — routes/api.php korumali grubundan (auth:isletmeyonetim-api)
 | dahil edilir. Kapsam + yetki her controller'da (StokTabanController). Yazmalar
 | STOK_V2_YAZMA_AKTIF arkasinda (kapaliyken 409). Web paneli (/v2/stok) ve Flutter
 | AYNI uclari kullanir; eski /api/stok/* esleme tablosu docs/flutter-uyum-yol-haritasi.md.
 */
Route::prefix('stok')->group(function () {
    Route::get('/ozet', [StokController::class, 'ozet']);
    Route::get('/form-verisi', [StokController::class, 'formVerisi']);
    Route::get('/dusuk-stok', [StokController::class, 'dusukStok']);
    Route::get('/musteri-ara', [StokController::class, 'musteriAra']);
    Route::get('/hareketler', [StokController::class, 'hareketler']);

    Route::get('/urunler', [StokUrunController::class, 'liste']);
    Route::get('/urunler/barkod', [StokUrunController::class, 'barkod']);
    Route::get('/urunler/{id}', [StokUrunController::class, 'goster'])->whereNumber('id');
    Route::get('/urunler/{id}/satis-raporu', [StokUrunController::class, 'satisRaporu'])->whereNumber('id');
    Route::post('/urunler', [StokUrunController::class, 'ekle']);
    Route::patch('/urunler/{id}', [StokUrunController::class, 'guncelle'])->whereNumber('id');
    Route::delete('/urunler/{id}', [StokUrunController::class, 'sil'])->whereNumber('id');

    Route::post('/hareketler', [StokIslemController::class, 'manuel']);
    Route::post('/alis', [StokIslemController::class, 'alis']);
    Route::post('/transfer', [StokIslemController::class, 'transfer']);
    Route::post('/sayim', [StokIslemController::class, 'sayim']);
    Route::post('/hizli-satis', [StokIslemController::class, 'hizliSatis']);

    $turler = array_keys(StokTanimController::TURLER);
    Route::get('/{tur}', [StokTanimController::class, 'liste'])->whereIn('tur', $turler);
    Route::post('/{tur}', [StokTanimController::class, 'ekle'])->whereIn('tur', $turler);
    Route::patch('/{tur}/{id}', [StokTanimController::class, 'guncelle'])->whereIn('tur', $turler)->whereNumber('id');
    Route::delete('/{tur}/{id}', [StokTanimController::class, 'sil'])->whereIn('tur', $turler)->whereNumber('id');
});
