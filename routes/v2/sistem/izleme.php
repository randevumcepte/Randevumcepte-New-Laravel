<?php

use App\Domain\SistemYonetim\SistemYetki;
use App\Http\Controllers\Api\V2\Sistem\AktiviteController;
use App\Http\Controllers\Api\V2\Sistem\DashboardController;
use App\Http\Controllers\Api\V2\Sistem\EkipController;
use App\Http\Controllers\Api\V2\Sistem\GuvenlikDuvariController;
use App\Http\Controllers\Api\V2\Sistem\GuvenlikLogController;
use App\Http\Controllers\Api\V2\Sistem\PerformansController;
use App\Http\Controllers\Api\V2\Sistem\RiskController;
use App\Http\Controllers\Api\V2\Sistem\SistemProfilController;
use App\Http\Controllers\Api\V2\Sistem\SistemSaglikController;
use Illuminate\Support\Facades\Route;

/* IZLEME + EKIP. Dashboard, risk ve profil tum rollere acik; destek rolu riskte yalniz kendi salonlarini gorur. */
Route::get('/dashboard', [DashboardController::class, 'ozet']);
Route::get('/dashboard/grafik', [DashboardController::class, 'grafik']);
Route::get('/risk', [RiskController::class, 'liste']);

Route::get('/profil', [SistemProfilController::class, 'ozet']);
Route::put('/profil', [SistemProfilController::class, 'guncelle']);
Route::post('/profil/sifre', [SistemProfilController::class, 'sifre'])->middleware('throttle:10,1');

Route::middleware('sistem.rol:'.SistemYetki::YONETIM)->group(function () {
    Route::get('/performans', [PerformansController::class, 'rapor']);

    Route::get('/ekip', [EkipController::class, 'liste']);
    Route::get('/ekip/secenekler', [EkipController::class, 'secenekler']);

    Route::get('/aktivite', [AktiviteController::class, 'liste']);
    Route::get('/aktivite/secenekler', [AktiviteController::class, 'secenekler']);
    Route::get('/aktivite/csv', [AktiviteController::class, 'csv']);

    Route::get('/guvenlik/girisler', [GuvenlikLogController::class, 'girisler']);
    Route::get('/guvenlik/impersonation', [GuvenlikLogController::class, 'impersonation']);

    Route::prefix('guvenlik-duvari')->controller(GuvenlikDuvariController::class)->group(function () {
        Route::get('/', 'ozet');
        Route::get('/engelliler', 'engelliler');
        Route::get('/kurallar', 'kurallar');
        Route::get('/olaylar', 'olaylar');
        Route::post('/engel-kaldir', 'engelKaldir');
        Route::post('/whitelist', 'whitelist');
        Route::post('/blacklist', 'blacklist');
        Route::delete('/kural/{id}', 'kuralSil')->whereNumber('id');
    });

    Route::get('/sistem-saglik', [SistemSaglikController::class, 'rapor']);
});

Route::middleware('sistem.rol:'.SistemYetki::SUPER_ADMIN)->group(function () {
    Route::post('/ekip', [EkipController::class, 'ekle']);
    Route::put('/ekip/{id}', [EkipController::class, 'guncelle'])->whereNumber('id');
    Route::post('/ekip/{id}/pasif', [EkipController::class, 'pasif'])->whereNumber('id');
});
