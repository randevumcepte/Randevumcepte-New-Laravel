<?php

use App\Http\Controllers\Api\V2\MobilOzetController;
use App\Http\Controllers\Api\V2\OzetController;
use Illuminate\Support\Facades\Route;

/*
 | OZET (dashboard) v2 — routes/api.php korumali grubundan (auth:isletmeyonetim-api) dahil
 | edilir. Web /v2/ozet ve Flutter ana ekrani AYNI uclar; kart/sekme kodlari ve yetkileri
 | App\Domain\Ozet\OzetKarti / OzetSekmesi'nde. v1 → v2 esleme docs/strangler-ozet.md.
 */
Route::prefix('ozet')->group(function () {
    Route::get('/mobil', [MobilOzetController::class, 'ozet']);
    Route::get('/mobil-karsilastirma', [MobilOzetController::class, 'karsilastirma']);
    Route::get('/acilis', [OzetController::class, 'acilis']);
    Route::get('/kart/{kod}', [OzetController::class, 'kart'])->where('kod', '[a-z_]+');
    Route::get('/sekme/{kod}', [OzetController::class, 'sekme'])->where('kod', '[a-z_]+');
});
