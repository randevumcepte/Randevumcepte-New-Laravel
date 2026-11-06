<?php

use App\Http\Controllers\Api\V2\Sistem\AiKrediController;
use App\Http\Controllers\Api\V2\Sistem\AsistanEgitimController;
use App\Http\Controllers\Api\V2\Sistem\DakikaController;
use App\Http\Controllers\Api\V2\Sistem\SistemWhatsappController;
use App\Http\Controllers\Api\V2\Sistem\WhatsappYonetimController;
use App\Http\Controllers\Api\V2\Sistem\WhatsappYonetimLogController;
use App\Http\Controllers\Api\V2\Sistem\WhatsappYonetimPaketController;
use App\Domain\SistemYonetim\SistemYetki as Yetki;
use Illuminate\Support\Facades\Route;

/*
 | ENTEGRASYON — WhatsApp Yonetim, Sistem WhatsApp, Dakika, AI Kredi, Asistan Egitimi. Rol belirtilmeyen okumalar tum roller.
 | Rol kumeleri SistemYetki'de: ayni sabitler ekrana donen izinleri de uretir.
 */
Route::prefix('whatsapp-yonetim')->group(function () {
    Route::get('/secenekler', [WhatsappYonetimController::class, 'secenekler']);
    Route::get('/ozet', [WhatsappYonetimController::class, 'ozet']);
    Route::get('/dagilim', [WhatsappYonetimController::class, 'dagilim']);
    Route::get('/salonlar', [WhatsappYonetimController::class, 'salonlar']);

    Route::middleware('sistem.rol:'.Yetki::ISLEM)->group(function () {
        Route::get('/loglar', [WhatsappYonetimLogController::class, 'liste']);
        Route::get('/loglar/csv', [WhatsappYonetimLogController::class, 'csv']);
        Route::get('/mesaj/{id}', [WhatsappYonetimLogController::class, 'mesaj'])->whereNumber('id');
        Route::get('/salon/{id}/aliciler', [WhatsappYonetimLogController::class, 'aliciler'])->whereNumber('id');
        Route::get('/salon/{id}/aliciler/{logId}/gecmis', [WhatsappYonetimLogController::class, 'aliciGecmisi'])->whereNumber(['id', 'logId']);
    });

    Route::get('/salon/{id}/paket', [WhatsappYonetimPaketController::class, 'detay'])->whereNumber('id');
    Route::middleware('sistem.rol:'.Yetki::YONETIM)->group(function () {
        Route::put('/salon/{id}/paket', [WhatsappYonetimPaketController::class, 'ayarla'])->whereNumber('id');
        Route::post('/salon/{id}/deneme', [WhatsappYonetimPaketController::class, 'denemeBaslat'])->whereNumber('id');
        Route::post('/salon/{id}/deneme/iptal', [WhatsappYonetimPaketController::class, 'denemeIptal'])->whereNumber('id');
        Route::post('/salon/{id}/kontor', [WhatsappYonetimPaketController::class, 'kontorYukle'])->whereNumber('id');
    });
});

Route::prefix('sistem-whatsapp')->middleware('sistem.rol:'.Yetki::SUPER_ADMIN)->group(function () {
    Route::get('/', [SistemWhatsappController::class, 'ayar']);
    Route::put('/ayar', [SistemWhatsappController::class, 'ayarKaydet']);
    Route::get('/durum', [SistemWhatsappController::class, 'durum']);
    Route::get('/qr', [SistemWhatsappController::class, 'qr']);
    Route::post('/baslat', [SistemWhatsappController::class, 'baslat']);
    Route::post('/eslestir', [SistemWhatsappController::class, 'eslestir']);
    Route::post('/cikis', [SistemWhatsappController::class, 'cikis']);
    Route::post('/test', [SistemWhatsappController::class, 'test']);
});

Route::get('/dakika', [DakikaController::class, 'liste']);
Route::put('/dakika/{salonId}', [DakikaController::class, 'kaydet'])->whereNumber('salonId')->middleware('sistem.rol:'.Yetki::YONETIM);

Route::prefix('ai-kredi')->group(function () {
    Route::get('/', [AiKrediController::class, 'rapor']);
    Route::get('/salonlar', [AiKrediController::class, 'salonlar']);
    Route::middleware('sistem.rol:'.Yetki::YONETIM)->group(function () {
        Route::put('/ayar', [AiKrediController::class, 'ayarKaydet']);
        Route::post('/ekle', [AiKrediController::class, 'krediEkle']);
        Route::post('/test-alarm', [AiKrediController::class, 'testAlarm']);
    });
});

Route::prefix('asistan-egitim')->group(function () {
    Route::get('/', [AsistanEgitimController::class, 'acilis']);
    Route::get('/kaliplar', [AsistanEgitimController::class, 'kaliplar']);
    Route::get('/cozulmeyenler', [AsistanEgitimController::class, 'cozulmeyenler']);
    Route::middleware('sistem.rol:'.Yetki::ISLEM)->group(function () {
        Route::post('/kalip', [AsistanEgitimController::class, 'kalipEkle']);
        Route::put('/kalip/{id}', [AsistanEgitimController::class, 'kalipGuncelle'])->whereNumber('id');
        Route::delete('/kalip/{id}', [AsistanEgitimController::class, 'kalipSil'])->whereNumber('id');
        Route::delete('/cozulmeyen/{id}', [AsistanEgitimController::class, 'cozulmeyenSil'])->whereNumber('id');
        Route::post('/pdf-coz', [AsistanEgitimController::class, 'pdfCoz'])->middleware('throttle:10,1');
        Route::post('/pdf-kaydet', [AsistanEgitimController::class, 'pdfKaydet']);
    });
});
