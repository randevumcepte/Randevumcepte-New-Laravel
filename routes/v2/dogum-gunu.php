<?php

use App\Http\Controllers\Api\V2\DogumGunuController;
use Illuminate\Support\Facades\Route;

/*
 | BUGUNUN DOGUM GUNLERI (mobil popup) — routes/api.php korumali grubundan dahil edilir. Eski /dogum-gunu/* karsiligi.
 */
Route::prefix('dogum-gunu')->group(function () {
    Route::get('/bugun', [DogumGunuController::class, 'bugun']);
    Route::post('/gonder', [DogumGunuController::class, 'gonder']);
    Route::post('/atla', [DogumGunuController::class, 'atla']);
});
