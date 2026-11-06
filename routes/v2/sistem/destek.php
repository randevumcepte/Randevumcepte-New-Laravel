<?php

use App\Domain\SistemYonetim\SistemYetki;
use App\Http\Controllers\Api\V2\Sistem\DuyuruController;
use App\Http\Controllers\Api\V2\Sistem\HazirCevapController;
use App\Http\Controllers\Api\V2\Sistem\ManuelOdemeController;
use App\Http\Controllers\Api\V2\Sistem\PaketSiparisController;
use App\Http\Controllers\Api\V2\Sistem\SmsPaketController;
use App\Http\Controllers\Api\V2\Sistem\TalepController;
use Illuminate\Support\Facades\Route;

/*
 | DESTEK + ICERIK — okuma tum ekibe; izleyici hicbir yazma ucuna giremez.
 | ISLEM: super_admin, yonetici, destek. YONETIM: super_admin, yonetici.
 */
Route::prefix('talep')->controller(TalepController::class)->group(function () {
    Route::get('/secenekler', 'secenekler');
    Route::get('/', 'liste');
    Route::get('/csv', 'csv')->middleware('sistem.rol:'.SistemYetki::YONETIM);
    Route::get('/{id}', 'detay')->whereNumber('id');

    Route::middleware('sistem.rol:'.SistemYetki::ISLEM)->group(function () {
        Route::post('/', 'olustur');
        Route::post('/{id}/yanit', 'yanit')->whereNumber('id');
        Route::post('/{id}/durum', 'durum')->whereNumber('id');
        Route::post('/{id}/ata', 'ata')->whereNumber('id');
        Route::post('/{id}/oncelik', 'oncelik')->whereNumber('id');
    });
});

Route::prefix('hazir-cevap')->controller(HazirCevapController::class)->group(function () {
    Route::get('/secenekler', 'secenekler');
    Route::get('/', 'liste');
    Route::get('/secim', 'secim');

    Route::middleware('sistem.rol:'.SistemYetki::ISLEM)->group(function () {
        Route::post('/', 'olustur');
        Route::put('/{id}', 'guncelle')->whereNumber('id');
        Route::delete('/{id}', 'sil')->whereNumber('id');
        Route::post('/{id}/kullan', 'kullan')->whereNumber('id');
    });
});

Route::prefix('duyuru')->controller(DuyuruController::class)->group(function () {
    Route::get('/secenekler', 'secenekler');
    Route::get('/', 'liste');
    Route::get('/{id}', 'detay')->whereNumber('id');
    Route::get('/{id}/okuyanlar', 'okuyanlar')->whereNumber('id');

    Route::middleware('sistem.rol:'.SistemYetki::YONETIM)->group(function () {
        Route::get('/{id}/form', 'form')->whereNumber('id');
        Route::post('/', 'olustur');
        Route::put('/{id}', 'guncelle')->whereNumber('id');
        Route::delete('/{id}', 'sil')->whereNumber('id');
    });
});

Route::prefix('sms-paket')->controller(SmsPaketController::class)->group(function () {
    Route::get('/secenekler', 'secenekler');
    Route::get('/', 'liste');

    Route::middleware('sistem.rol:'.SistemYetki::YONETIM)->group(function () {
        Route::post('/', 'olustur');
        Route::put('/{id}', 'guncelle')->whereNumber('id');
        Route::delete('/{id}', 'sil')->whereNumber('id');
    });
});

Route::prefix('paket-siparis')->controller(PaketSiparisController::class)->group(function () {
    Route::get('/', 'liste');
    Route::post('/{id}/yuklendi', 'yuklendi')->whereNumber('id')->middleware('sistem.rol:'.SistemYetki::ISLEM);
});

Route::prefix('manuel-odeme')->controller(ManuelOdemeController::class)->group(function () {
    Route::get('/secenekler', 'secenekler');
    Route::post('/', 'olustur')->middleware('sistem.rol:'.SistemYetki::ISLEM);
});
