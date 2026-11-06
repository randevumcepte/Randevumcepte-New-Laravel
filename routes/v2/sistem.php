<?php

use App\Domain\SistemYonetim\SistemYetki;
use App\Http\Controllers\Api\V2\Sistem\ImpersonationController;
use App\Http\Controllers\Api\V2\Sistem\SistemKabukController;
use App\Http\Controllers\Api\V2\Sistem\SistemOturumController;
use Illuminate\Support\Facades\Route;

/*
 | SISTEM YONETIMI — /api/v2/sistem/*. Kimlik sistemyoneticileri (guard sistemyonetim-api); isletme ve musteri
 | token'lari giremez. Rol kapisi rotada: sistem.rol:<roller>. Roller: super_admin, yonetici, destek, izleyici.
 | izleyici yalniz okur (tum yazma uclari YAZAN ya da daha dar). docs/strangler-sistem-yonetim.md
 */
Route::prefix('sistem')->group(function () {
    Route::post('/oturum/giris', [SistemOturumController::class, 'giris'])->middleware('throttle:20,1');

    Route::middleware('auth:sistemyonetim-api')->group(function () {
        Route::post('/oturum/cikis', [SistemOturumController::class, 'cikis']);
        Route::get('/kabuk', [SistemKabukController::class, 'kabuk']);
        Route::get('/bildirim', [SistemKabukController::class, 'bildirim']);
        Route::get('/ara', [SistemKabukController::class, 'ara']);
        Route::get('/salon-ara', [SistemKabukController::class, 'salonAra']);

        Route::post('/salon/{id}/hesabina-gir', [ImpersonationController::class, 'baslat'])->whereNumber('id')->middleware('sistem.rol:'.SistemYetki::ISLEM);
        Route::post('/impersonation/{logId}/bitir', [ImpersonationController::class, 'bitir'])->whereNumber('logId');

        require __DIR__.'/sistem/salon.php';
        require __DIR__.'/sistem/destek.php';
        require __DIR__.'/sistem/izleme.php';
        require __DIR__.'/sistem/entegrasyon.php';
    });
});
