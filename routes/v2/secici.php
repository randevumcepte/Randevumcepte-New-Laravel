<?php

use App\Http\Controllers\Api\V2\SeciciController;
use Illuminate\Support\Facades\Route;

/*
 | ORTAK SECIM LISTELERI — routes/api.php korumali grubundan dahil edilir. Web ve Flutter ayni uclari
 | kullanir; salona bagli her kullanici okur (SeciciController).
 */
Route::prefix('secici')->group(function () {
    Route::get('/hizmetler', [SeciciController::class, 'hizmetler']);
    Route::get('/cihazlar', [SeciciController::class, 'cihazlar']);
    Route::get('/odalar', [SeciciController::class, 'odalar']);
    Route::get('/salon', [SeciciController::class, 'salonBilgi']);
    Route::get('/sehirler', [SeciciController::class, 'sehirler']);
});
