<?php

use App\Http\Controllers\Api\V2\HareketController;
use Illuminate\Support\Facades\Route;

/*
 | LOG HAREKETLERİ v2 (salon_aktivite_log, SALT OKUMA) — routes/api.php korumalı grubundan
 | (auth:isletmeyonetim-api) dahil edilir. Kapsam + rol kuralı HareketController'da.
 | Web (/v2/log-hareketleri + lib/v2-hareket.js gömülü tablolar) ve Flutter AYNI uçları kullanır.
 */
Route::prefix('hareketler')->group(function () {
    Route::get('/', [HareketController::class, 'liste']);
    Route::get('/secenekler', [HareketController::class, 'secenekler']);
});
