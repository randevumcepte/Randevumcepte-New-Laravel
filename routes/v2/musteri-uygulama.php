<?php

use App\Http\Controllers\Api\V2\MusteriDersController;
use App\Http\Controllers\Api\V2\MusteriHesapController;
use App\Http\Controllers\Api\V2\MusteriReklamController;
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

    // Randevularım + online randevu oluşturma + paket seçimi.
    Route::get('/randevular', [MusteriHesapController::class, 'randevular']);
    Route::post('/randevular', [MusteriHesapController::class, 'randevuOlustur']);
    Route::post('/randevular/{id}/iptal', [MusteriHesapController::class, 'randevuIptal'])->whereNumber('id');
    Route::get('/paketlerim', [MusteriHesapController::class, 'paketlerim']);

    // Yorum + uygunsuz yorum bildirimi.
    Route::post('/yorumlar', [MusteriHesapController::class, 'yorumYap']);
    Route::post('/yorumlar/{id}/bildir', [MusteriHesapController::class, 'yorumBildir'])->whereNumber('id');

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
