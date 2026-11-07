<?php

use App\Http\Controllers\Api\V2\WhatsappKontorSayfaController;
use Illuminate\Support\Facades\Route;

/*
 | MOBIL KONTOR WEBVIEW — eski /mobil/webview-giris + whatsapp-kontor-mobil. WebView token basligi gonderemez; adres yalniz
 | token'li GET /whatsapp/kontor/sayfa-baglantisi cevabinda uretilir. Goreli imza: proxy arkasinda sema degisse de gecerli.
 */
Route::prefix('whatsapp-kontor-sayfa')->middleware('signed:relative')->group(function () {
    Route::get('/', [WhatsappKontorSayfaController::class, 'goster'])->name('v2.whatsapp-kontor.sayfa');
    Route::post('/talep', [WhatsappKontorSayfaController::class, 'talep'])->name('v2.whatsapp-kontor.talep');
});
