<?php

use App\Http\Controllers\Api\V2\CagriAyarController;
use App\Http\Controllers\Api\V2\CagriIslemController;
use App\Http\Controllers\Api\V2\CagriListeController;
use App\Http\Controllers\Api\V2\CagriMerkeziController;
use App\Http\Controllers\Api\V2\CagriRaporController;
use Illuminate\Support\Facades\Route;

/*
 | ÇAĞRI MERKEZİ v2 — routes/api.php korumalı grubundan (auth:isletmeyonetim-api) dahil edilir.
 | Eski 4 sayfa (arama-listelerim, arama-randevu-takvim, arama-cagri-kayitlari, arama-dashboard)
 | + v1 /api/v1/cagri-merkezi/* TEK uç kümesinde; web /v2/cagri-merkezi (sekmeli) ve Flutter
 | aynı uçları kullanır. Erişim (kapsam + üyelik paketi + rol + kayıt düzeyi) servislerde;
 | yazmalar CAGRI_MERKEZI_V2_YAZMA_AKTIF arkasında (kapalıyken 409). v1 → v2 eşleme:
 | docs/strangler-cagri-merkezi.md.
 */
Route::prefix('cagri-merkezi')->group(function () {
    // Açılış + Çalışma Ekranı (okuma)
    Route::get('/acilis', [CagriMerkeziController::class, 'acilis']);
    Route::get('/listeler', [CagriMerkeziController::class, 'listeler']);
    Route::get('/listeler/{id}/musteriler', [CagriMerkeziController::class, 'kuyruk'])->whereNumber('id');
    Route::get('/kayit/{id}', [CagriMerkeziController::class, 'kayit'])->whereNumber('id');
    Route::get('/kayit/{id}/gecmis', [CagriMerkeziController::class, 'gecmis'])->whereNumber('id');
    Route::get('/kayit/{id}/ses-kayitlari', [CagriMerkeziController::class, 'sesKayitlari'])->whereNumber('id');
    Route::get('/kayit/{id}/musteri', [CagriMerkeziController::class, 'musteri'])->whereNumber('id');
    Route::get('/musteri-ara', [CagriMerkeziController::class, 'musteriAra']);
    Route::get('/musteri/{userId}/randevu-gecmisi', [CagriMerkeziController::class, 'randevuGecmisi'])->whereNumber('userId');

    // Aranacak müşteri işlemleri
    Route::post('/kayit/{id}/ara', [CagriIslemController::class, 'ara'])->whereNumber('id');
    Route::post('/kayit/{id}/sonuc', [CagriIslemController::class, 'sonuc'])->whereNumber('id');
    Route::post('/kayit/{id}/randevu-verildi', [CagriIslemController::class, 'randevuVerildi'])->whereNumber('id');
    Route::post('/kayit/{id}/iptal', [CagriIslemController::class, 'iptal'])->whereNumber('id');
    Route::post('/kayit/{id}/ertele', [CagriIslemController::class, 'ertele'])->whereNumber('id');
    Route::post('/kayit/{id}/satis', [CagriIslemController::class, 'satis'])->whereNumber('id');
    Route::post('/kayit/{id}/whatsapp', [CagriIslemController::class, 'whatsapp'])->whereNumber('id');
    Route::post('/kayit/{id}/anket', [CagriIslemController::class, 'anket'])->whereNumber('id');
    Route::post('/ara', [CagriIslemController::class, 'araNumara']);
    Route::post('/arama-randevusu', [CagriIslemController::class, 'aramaRandevusu']);

    // Takvim / hatırlatma / ajanda / performans / çağrı kayıtları (okuma)
    Route::get('/takvim', [CagriRaporController::class, 'takvim']);
    Route::get('/yaklasan', [CagriRaporController::class, 'yaklasan']);
    Route::get('/ajanda', [CagriRaporController::class, 'ajanda']);
    Route::get('/performans', [CagriRaporController::class, 'performans']);
    Route::get('/performans/{personelId}', [CagriRaporController::class, 'personelDetay'])->whereNumber('personelId');
    Route::get('/cagri-kayitlari', [CagriRaporController::class, 'cagriKayitlari']);

    // Liste oluşturma + yönetimi (yönetici)
    Route::post('/listeler/onizleme', [CagriListeController::class, 'onizleme']);
    Route::post('/listeler', [CagriListeController::class, 'olustur']);
    Route::get('/listeler/{id}/segmentler', [CagriListeController::class, 'segmentler'])->whereNumber('id');
    Route::get('/listeler/{id}/csv', [CagriListeController::class, 'csv'])->whereNumber('id');
    Route::patch('/listeler/{id}/durum', [CagriListeController::class, 'durum'])->whereNumber('id');
    Route::patch('/listeler/{id}/personel', [CagriListeController::class, 'personel'])->whereNumber('id');
    Route::post('/listeler/{id}/tasi', [CagriListeController::class, 'tasi'])->whereNumber('id');
    Route::delete('/listeler/{id}', [CagriListeController::class, 'sil'])->whereNumber('id');

    // Ayarlar: görüşme scriptleri + sonuç kategorileri (yönetici)
    Route::get('/ayarlar', [CagriAyarController::class, 'getir']);
    Route::post('/scriptler', [CagriAyarController::class, 'scriptKaydet']);
    Route::patch('/scriptler/{id}', [CagriAyarController::class, 'scriptKaydet'])->whereNumber('id');
    Route::delete('/scriptler/{id}', [CagriAyarController::class, 'scriptSil'])->whereNumber('id');
    Route::post('/kategoriler', [CagriAyarController::class, 'kategoriKaydet']);
    Route::patch('/kategoriler/{id}', [CagriAyarController::class, 'kategoriKaydet'])->whereNumber('id');
    Route::delete('/kategoriler/{id}', [CagriAyarController::class, 'kategoriSil'])->whereNumber('id');
});
