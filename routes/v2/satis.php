<?php

use App\Http\Controllers\Api\V2\SatisMobilController;
use Illuminate\Support\Facades\Route;

/*
 | MOBIL SATIS — routes/api.php korumali grubundan (auth:isletmeyonetim-api) dahil edilir.
 | Kimlik + salon kapsami controller'da. Liste, silme ve fatura uclari routes/api.php'deki ortak
 | /satislar, /adisyon, /kalem yollarinda `mobil` bayragiyla secilir.
 */
Route::prefix('satislar')->group(function () {
    Route::get('/paket-gunluk', [SatisMobilController::class, 'paketGunluk']);
    Route::get('/urun-gunluk', [SatisMobilController::class, 'urunGunluk']);
    Route::get('/faturasiz-gizle', [SatisMobilController::class, 'faturasizDurum']);
    Route::get('/musteri-turu', [SatisMobilController::class, 'musteriTuru']);
    Route::get('/musteri-karti/{id}', [SatisMobilController::class, 'musteriKarti'])->whereNumber('id');

    Route::post('/mobil/hizmet', [SatisMobilController::class, 'hizmetKaydet']);
    Route::post('/mobil/urun', [SatisMobilController::class, 'urunKaydet']);
    Route::post('/mobil/paket', [SatisMobilController::class, 'paketKaydet']);
});
