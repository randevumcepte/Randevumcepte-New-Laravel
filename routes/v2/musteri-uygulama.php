<?php

use App\Http\Controllers\Api\V2\MusteriReklamController;
use Illuminate\Support\Facades\Route;

/*
 | MÜŞTERİ UYGULAMASI v2 — routes/api.php 'auth:api' (users, legacy-passport) grubundan dahil edilir.
 | Müşteri kimliği token'dan; gövdedeki user_id kullanılmaz. İşletme (isletmeyonetim-api) token'ı
 | bu uçlara GİREMEZ (farklı provider tablosu).
 */
Route::prefix('musteri-uygulama')->group(function () {
    // Bildirim reklamları: uygulama içi kart / açılış popup + tek dokunuş kupon.
    Route::get('/reklamlar', [MusteriReklamController::class, 'liste']);
    Route::post('/reklamlar/{id}/kupon', [MusteriReklamController::class, 'kuponKap'])->whereNumber('id');
});
