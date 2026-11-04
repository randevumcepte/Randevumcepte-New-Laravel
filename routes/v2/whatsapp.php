<?php

use App\Http\Controllers\Api\V2\WhatsappBaglantiController;
use App\Http\Controllers\Api\V2\WhatsappController;
use App\Http\Controllers\Api\V2\WhatsappGonderimController;
use App\Http\Controllers\Api\V2\WhatsappKontorController;
use Illuminate\Support\Facades\Route;

/*
 | WHATSAPP YÖNETİMİ v2 — routes/api.php korumalı grubundan (auth:isletmeyonetim-api) dahil edilir.
 | Kapsam + yetki (pazarlama.whatsapp_gonder) controller'da. Web (/v2/whatsapp) ve Flutter AYNI uçlar.
 */
Route::prefix('whatsapp')->group(function () {
    Route::get('/', [WhatsappController::class, 'acilis']);
    Route::get('/kanal', [WhatsappController::class, 'kanal']);
    Route::put('/kanal', [WhatsappController::class, 'kanalDegistir']);
    Route::get('/linkler', [WhatsappController::class, 'linkler']);
    Route::put('/linkler', [WhatsappController::class, 'linkKaydet']);

    Route::get('/durum', [WhatsappBaglantiController::class, 'durum']);
    Route::get('/qr', [WhatsappBaglantiController::class, 'qr']);
    Route::post('/baslat', [WhatsappBaglantiController::class, 'baslat']);
    Route::post('/pair-phone', [WhatsappBaglantiController::class, 'pairPhone']);
    Route::post('/cikis', [WhatsappBaglantiController::class, 'cikis']);

    Route::get('/ozet', [WhatsappGonderimController::class, 'ozet']);
    Route::get('/loglar', [WhatsappGonderimController::class, 'loglar']);
    Route::get('/aliciler', [WhatsappGonderimController::class, 'aliciler']);
    Route::get('/aliciler/{id}/gecmis', [WhatsappGonderimController::class, 'aliciGecmisi'])->whereNumber('id');

    Route::get('/kontor', [WhatsappKontorController::class, 'durum']);
    Route::post('/kontor/talep', [WhatsappKontorController::class, 'talep']);
});
