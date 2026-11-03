<?php

use App\Http\Controllers\Api\V2\SeansController;
use Illuminate\Support\Facades\Route;

/*
 | SEANS TAKIBI v2 — routes/api.php korumali grubundan (auth:isletmeyonetim-api)
 | dahil edilir. Kapsam + yetki (paket.seans_takip) controller'da. Yazmalar
 | SEANS_V2_YAZMA_AKTIF arkasinda (kapaliyken 409). Web paneli (/v2/seans-takibi,
 | musteri detay "Seanslar") ve Flutter AYNI uclari kullanir; v1 esleme tablosu
 | docs/flutter-uyum-yol-haritasi.md.
 */
Route::prefix('seans')->group(function () {
    Route::get('/', [SeansController::class, 'liste']);
    Route::post('/', [SeansController::class, 'ekle']);
    Route::patch('/{id}', [SeansController::class, 'durum'])->whereNumber('id');
    Route::get('/{id}/cihaz', [SeansController::class, 'cihaz'])->whereNumber('id');
    Route::put('/{id}/cihaz', [SeansController::class, 'cihazKaydet'])->whereNumber('id');
});
