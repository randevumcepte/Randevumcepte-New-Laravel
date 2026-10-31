<?php

use App\Http\Controllers\Api\V2\AdisyonController;
use App\Http\Controllers\Api\V2\AlacakController;
use App\Http\Controllers\Api\V2\KabukController;
use App\Http\Controllers\Api\V2\KatalogController;
use App\Http\Controllers\Api\V2\PersonelController;
use App\Http\Controllers\Api\V2\PersonelYetkiController;
use App\Http\Controllers\Api\V2\RandevuController;
use App\Http\Controllers\Api\V2\RandevuDurumController;
use App\Http\Controllers\Api\V2\RandevuTelafiController;
use App\Http\Controllers\Api\V2\SatisController;
use App\Http\Controllers\Api\V2\SatisKalemController;
use App\Http\Controllers\Api\V2\TahsilatController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v2 — Strangler Geçiş Uçları
|--------------------------------------------------------------------------
|
| KVKK / veri koruma ilkesi (ZORUNLU):
|   - KİŞİSEL VERİ içeren HER uç 'auth:isletmeyonetim-api' (legacy-passport)
|     arkasindadir + controller icinde GuvenlikKapisi ile KAPSAM (isletme/sube)
|     + YETKI denetimi yapar. Varsayilan KORUMALI; yeni uclar korumali gruba
|     eklenir. Yalnizca kisisel veri ICERMEYEN uclar (health) public olabilir.
|   - Veri minimizasyonu: Resource/servis yalnizca gereken alanlari dondurur
|     (ham model dokumu YOK). Telefon vb. hassas alan maskelenir (musteri.telefon_gor).
|
| Hem web paneli (Vue) hem Flutter AYNI uclari tuketir; is mantigi servislerde.
|
*/

Route::prefix('v2')->group(function () {

    /*
     | PUBLIC — kisisel veri ICERMEZ (yalnizca servis + DB sagligi).
     */
    Route::get('/health', function () {
        $veritabani = 'down';

        try {
            DB::connection()->getPdo();
            $veritabani = 'up';
        } catch (\Throwable $e) {
            $veritabani = 'error';
        }

        return response()->json([
            'servis'     => 'randevumcepte-v2',
            'durum'      => $veritabani === 'up' ? 'ok' : 'degraded',
            'veritabani' => $veritabani,
            'surum'      => 'v2',
        ], $veritabani === 'up' ? 200 : 503);
    });

    /*
     | KORUMALI — kisisel veri iceren TUM uclar buraya. Kimlik ZORUNLU.
     | Kapsam + yetki her controller'da GuvenlikKapisi ile uygulanir.
     */
    Route::middleware('auth:isletmeyonetim-api')->group(function () {
        // Panel kabugu (sol menu + header verisi) — v2 ekranlarinin ortak shell'i.
        Route::get('/kabuk', [KabukController::class, 'goster']);
        Route::get('/adisyon/{id}', [AdisyonController::class, 'goster'])->whereNumber('id');

        // RANDEVU (Faz 1 — SALT OKUMA). Yazma yollari sonraki fazlarda.
        Route::get('/randevu/takvim', [RandevuController::class, 'takvim']);
        Route::get('/randevu/{id}', [RandevuController::class, 'goster'])->whereNumber('id');

        // RANDEVU DURUM (Faz 2) — varsayilan dry_run; gercek yazma
        // config('randevu.yazma_aktif') kill-switch arkasinda (kapaliyken 409).
        Route::post('/randevu/{id}/geldi', [RandevuDurumController::class, 'geldi'])->whereNumber('id');
        Route::post('/randevu/{id}/gelmedi', [RandevuDurumController::class, 'gelmedi'])->whereNumber('id');
        Route::delete('/randevu/{id}/geldi-isareti', [RandevuDurumController::class, 'isaretKaldir'])->whereNumber('id');
        Route::post('/randevu/{id}/telafi', [RandevuDurumController::class, 'telafi'])->whereNumber('id');
        Route::post('/randevu/{id}/iptal', [RandevuDurumController::class, 'iptal'])->whereNumber('id');

        // TELAFI RANDEVUSU (A tipi: ucretsiz duzeltme + prim devri) — ayni kill-switch.
        Route::get('/randevu/telafi-secenekler', [RandevuTelafiController::class, 'secenekler']);
        Route::post('/randevu/kalem/{rh}/telafi-randevusu', [RandevuTelafiController::class, 'olustur'])->whereNumber('rh');
        Route::post('/randevu/{id}/onayla', [RandevuDurumController::class, 'onayla'])->whereNumber('id');

        Route::get('/satislar', [SatisController::class, 'index']);
        Route::get('/katalog', [KatalogController::class, 'index']);
        // YAZMA — su an yalnizca DRY-RUN (controller gercek yazmayi reddeder).
        Route::post('/tahsilat', [TahsilatController::class, 'ekle']);
        // DUZENLE (iade+yeniden yaz, atomik) — flag korumali (satis.tahsilat_al).
        Route::patch('/tahsilat/{id}', [TahsilatController::class, 'guncelle'])->whereNumber('id');
        // IADE/SILME — flag korumali (satis.tahsilat_sil).
        Route::delete('/tahsilat/{id}', [TahsilatController::class, 'sil'])->whereNumber('id');

        // SATIS KALEM yonetimi (Satis & Tahsilat Duzenleme) — flag korumali.
        Route::post('/adisyon/{adisyon}/kalem', [SatisKalemController::class, 'ekle'])->whereNumber('adisyon');
        Route::patch('/kalem/urun/{id}', [SatisKalemController::class, 'urunAdet'])->whereNumber('id');
        Route::delete('/kalem/{tip}/{id}', [SatisKalemController::class, 'sil'])->where('tip', 'hizmet|urun|paket')->whereNumber('id');

        // PLANLANAN ALACAK (receivable) — flag korumali (finans.alacak_yonet).
        Route::post('/alacak', [AlacakController::class, 'ekle']);
        Route::patch('/alacak/{id}', [AlacakController::class, 'guncelle'])->whereNumber('id');
        Route::delete('/alacak/{id}', [AlacakController::class, 'sil'])->whereNumber('id');

        /*
         | PERSONEL YONETIMI (Faz 1 — SALT OKUMA). Her uc GuvenlikKapisi ile
         | kapsam + yetki denetler. Yetkiye gore parcali: liste_gor / yetki_yonet.
         | Yazma uclari (ekle/duzenle, durum, siralama, takvim, saat, arsivle,
         | yetki kaydet) Faz 2'de config('personel.yazma_aktif') kill-switch arkasinda.
         */
        Route::get('/personel', [PersonelController::class, 'liste']);
        Route::get('/personel-yetki/sema', [PersonelYetkiController::class, 'sema']);
        Route::get('/personel-yetki/benim', [PersonelYetkiController::class, 'benimYetkilerim']);
        Route::get('/personel/{id}', [PersonelController::class, 'goster'])->whereNumber('id');
        Route::get('/personel/{id}/calisma-saatleri', [PersonelController::class, 'calismaSaatleri'])->whereNumber('id');
        Route::get('/personel/{id}/mola-saatleri', [PersonelController::class, 'molaSaatleri'])->whereNumber('id');
        Route::get('/personel/{id}/gelecek-hizmetler', [PersonelController::class, 'gelecekHizmetler'])->whereNumber('id');
        Route::get('/personel/{id}/yetki', [PersonelYetkiController::class, 'getir'])->whereNumber('id');

        /*
         | PERSONEL YAZMA (Faz 2 — config('personel.yazma_aktif') kill-switch arkasinda;
         | kapaliyken 409). ekle/duzenle -> personel.ekle_duzenle; arsivle -> personel.sil;
         | yetki kaydet -> personel.yetki_yonet.
         */
        Route::post('/personel', [PersonelController::class, 'ekleDuzenle']);
        Route::patch('/personel/{id}', [PersonelController::class, 'ekleDuzenle'])->whereNumber('id');
        Route::patch('/personel/{id}/durum', [PersonelController::class, 'durum'])->whereNumber('id');
        Route::patch('/personel/{id}/siralama', [PersonelController::class, 'siralama'])->whereNumber('id');
        Route::patch('/personel/{id}/takvim-gorunur', [PersonelController::class, 'takvimToggle'])->whereNumber('id');
        Route::patch('/personel/{id}/saatler', [PersonelController::class, 'saatKaydet'])->whereNumber('id');
        Route::delete('/personel/{id}', [PersonelController::class, 'arsivle'])->whereNumber('id');
        Route::patch('/personel/{id}/yetki', [PersonelYetkiController::class, 'kaydet'])->whereNumber('id');
    });

});
