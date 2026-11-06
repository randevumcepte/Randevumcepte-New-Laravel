<?php

use App\Domain\SistemYonetim\SistemYetki;
use App\Http\Controllers\Api\V2\Sistem\SalonController;
use App\Http\Controllers\Api\V2\Sistem\SalonHesapController;
use App\Http\Controllers\Api\V2\Sistem\SalonNotController;
use App\Http\Controllers\Api\V2\Sistem\SalonUyelikController;
use App\Http\Controllers\Api\V2\Sistem\SalonYazmaController;
use Illuminate\Support\Facades\Route;

$yonetim = 'sistem.rol:'.SistemYetki::YONETIM;
$notYazan = 'sistem.rol:'.SistemYetki::ISLEM;

Route::get('/salon', [SalonController::class, 'liste']);
Route::get('/salon/secenekler', [SalonController::class, 'secenekler']);
Route::get('/salon/{id}', [SalonController::class, 'detay'])->whereNumber('id');

Route::middleware($yonetim)->group(function () {
    Route::get('/salon/csv', [SalonController::class, 'csv']);
    Route::get('/salon/{id}/musteriler/csv', [SalonController::class, 'musterilerCsv'])->whereNumber('id');

    Route::post('/salon', [SalonYazmaController::class, 'ekle']);
    Route::post('/salon/toplu-islem', [SalonYazmaController::class, 'toplu']);

    Route::prefix('/salon/{id}')->whereNumber('id')->group(function () {
        Route::put('/bilgi', [SalonYazmaController::class, 'bilgi']);
        Route::post('/askiya-al', [SalonYazmaController::class, 'askiyaAl']);
        Route::post('/aktif-et', [SalonYazmaController::class, 'aktifEt']);
        Route::post('/mt-ata', [SalonYazmaController::class, 'temsilciAta']);

        Route::post('/sure-uzat', [SalonUyelikController::class, 'sureUzat']);
        Route::post('/paketli-uzat', [SalonUyelikController::class, 'paketliUzat']);
        Route::post('/lisans-aktif', [SalonUyelikController::class, 'lisansAktif']);
        Route::post('/lisans-kapat', [SalonUyelikController::class, 'lisansKapat']);

        Route::post('/hesap-pasif', [SalonHesapController::class, 'aktiflik']);
    });
});

Route::post('/salon/{id}/sahip-yap', [SalonHesapController::class, 'sahipYap'])->whereNumber('id')->middleware('sistem.rol:'.SistemYetki::SUPER_ADMIN);

Route::middleware($notYazan)->group(function () {
    Route::post('/salon/{id}/not', [SalonNotController::class, 'ekle'])->whereNumber('id');
    Route::delete('/salon/not/{notId}', [SalonNotController::class, 'sil'])->whereNumber('notId');
    Route::post('/salon/not/{notId}/pin', [SalonNotController::class, 'sabit'])->whereNumber('notId');
});
