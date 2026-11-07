<?php

use App\Http\Controllers\Api\V2\MusteriBildirimController;
use App\Http\Controllers\Api\V2\MusteriCarkController;
use App\Http\Controllers\Api\V2\MusteriDersController;
use App\Http\Controllers\Api\V2\MusteriHesapController;
use App\Http\Controllers\Api\V2\MusteriReklamController;
use App\Http\Controllers\Api\V2\MusteriResimController;
use Illuminate\Support\Facades\Route;

/*
 | MÜŞTERİ UYGULAMASI v2 — routes/api.php 'auth:api' (users, legacy-passport) grubundan dahil edilir.
 | Müşteri kimliği token'dan; gövdedeki user_id kullanılmaz. İşletme (isletmeyonetim-api) token'ı
 | bu uçlara GİREMEZ (farklı provider tablosu).
 */
Route::prefix('musteri-uygulama')->group(function () {
    // Profil + oturum (mini site müşteri paneli + uygulama aynı uçlar).
    Route::get('/ben', [MusteriHesapController::class, 'ben']);
    Route::patch('/ben', [MusteriHesapController::class, 'guncelle']);
    Route::post('/ben/resim', [MusteriHesapController::class, 'resimYukle']);
    Route::delete('/ben/resim', [MusteriHesapController::class, 'resimKaldir']);
    Route::post('/ben/sifre', [MusteriHesapController::class, 'sifreDegistir']);
    Route::post('/ben/cikis', [MusteriHesapController::class, 'cikis']);
    Route::patch('/ben/saglik', [MusteriHesapController::class, 'saglik']);
    Route::get('/ozet', [MusteriHesapController::class, 'ozet']);
    Route::post('/hesap-silme-talebi', [MusteriHesapController::class, 'hesapSilmeTalebi']);

    // Randevularım + online randevu oluşturma + paket seçimi.
    Route::get('/randevular', [MusteriHesapController::class, 'randevular']);
    Route::post('/randevular', [MusteriHesapController::class, 'randevuOlustur']);
    Route::post('/randevular/{id}/iptal', [MusteriHesapController::class, 'randevuIptal'])->whereNumber('id');
    Route::get('/paketlerim', [MusteriHesapController::class, 'paketlerim']);
    Route::get('/seanslar', [MusteriHesapController::class, 'seanslar']);

    // Islem fotograflari.
    Route::get('/resimler', [MusteriResimController::class, 'liste']);
    Route::post('/resimler', [MusteriResimController::class, 'ekle']);
    Route::delete('/resimler', [MusteriResimController::class, 'sil']);
    Route::patch('/resimler/not', [MusteriResimController::class, 'notYaz']);

    // Carkifelek + puan odulleri.
    Route::get('/cark', [MusteriCarkController::class, 'durum']);
    Route::post('/cark/cevir', [MusteriCarkController::class, 'cevir']);
    Route::get('/cark/odullerim', [MusteriCarkController::class, 'odullerim']);
    Route::get('/cark/puan-odulleri', [MusteriCarkController::class, 'puanOdullerim']);
    Route::post('/cark/puan-odulleri/{odulId}/talep', [MusteriCarkController::class, 'puanOdulTalep'])->whereNumber('odulId');

    // Yorum + uygunsuz yorum bildirimi.
    Route::post('/yorumlar', [MusteriHesapController::class, 'yorumYap']);
    Route::post('/yorumlar/{id}/bildir', [MusteriHesapController::class, 'yorumBildir'])->whereNumber('id');

    // Bildirim kutusu + FCM cihaz kaydı (panel uçlarıyla aynı servisler).
    Route::get('/bildirimler', [MusteriBildirimController::class, 'liste']);
    Route::get('/bildirimler/sayac', [MusteriBildirimController::class, 'sayac']);
    Route::post('/bildirimler/cihaz', [MusteriBildirimController::class, 'cihazKaydet']);
    Route::delete('/bildirimler/cihaz', [MusteriBildirimController::class, 'cihazSil']);
    Route::post('/bildirimler/tumu/okundu', [MusteriBildirimController::class, 'tumunuOku']);
    Route::post('/bildirimler/{id}/okundu', [MusteriBildirimController::class, 'okundu'])->whereNumber('id');

    // Bildirim reklamları: uygulama içi kart / açılış popup + tek dokunuş kupon.
    Route::get('/reklamlar', [MusteriReklamController::class, 'liste']);
    Route::post('/reklamlar/{id}/kupon', [MusteriReklamController::class, 'kuponKap'])->whereNumber('id');

    // Grup dersi: online rezervasyon + kendi derslerim + katilim bildirimi + vucut olcumlerim.
    Route::get('/grup-dersleri', [MusteriDersController::class, 'uygunDersler']);
    Route::post('/grup-dersleri/{oturumId}/rezervasyon', [MusteriDersController::class, 'rezervasyon'])->whereNumber('oturumId');
    Route::get('/derslerim', [MusteriDersController::class, 'derslerim']);
    Route::post('/derslerim/{katilimciId}/katilim', [MusteriDersController::class, 'katilim'])->whereNumber('katilimciId');
    Route::get('/olcumlerim', [MusteriDersController::class, 'olcumlerim']);
});
