<?php

use App\Http\Controllers\Api\V2\AjandaController;
use Illuminate\Support\Facades\Route;

/*
 | AJANDA NOTLARI — routes/api.php korumali grubundan dahil edilir. Web ve Flutter ayni uclari
 | kullanir; kullanici yalniz kendi notunu gorur ve degistirir (AjandaController).
 */
Route::prefix('ajanda')->group(function () {
    Route::get('/', [AjandaController::class, 'liste']);
    Route::post('/', [AjandaController::class, 'ekle']);
    Route::patch('/{id}', [AjandaController::class, 'guncelle'])->whereNumber('id');
    Route::delete('/{id}', [AjandaController::class, 'sil'])->whereNumber('id');
    Route::post('/{id}/okundu', [AjandaController::class, 'okundu'])->whereNumber('id');
});
