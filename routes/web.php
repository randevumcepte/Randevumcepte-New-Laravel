<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 | Tahsilat v2 — web paneli island host sayfasi (ilk tuketici, pilot).
 | Sayfa yalnizca HTML iskelet; veri /api/v2 (Bearer) ile yuklenir. Token
 | pilotta ?token=... ile saglanir (uretimde eski panel enjekte eder).
 */
Route::get('/v2/tahsilat/{adisyon}', function ($adisyon) {
    return view('v2.tahsilat', ['adisyonId' => (int) $adisyon]);
})->whereNumber('adisyon');

/*
 | Satis Takibi v2 — adisyon/satis listesi host sayfasi (ayni island deseni).
 | Veri /api/v2/satislar (Bearer) ile yuklenir; salon_id ?sube= query'sinden
 | gelir (kabuk sube secimiyle de uyumlu). Eski panel "Satis Takibi" karsiligi.
 */
Route::get('/v2/satislar/{sube?}', function ($sube = null) {
    // sube hem path (/v2/satislar/20) hem query (?sube=20) ile kabul edilir.
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.satislar', ['salonId' => $salonId]);
})->whereNumber('sube');

/*
 | Personel Yonetimi v2 — personel listesi + ekle/duzenle + yetki editoru host
 | sayfasi (ayni island deseni). Veri /api/v2/personel* (Bearer) ile yuklenir;
 | yazma uclari PERSONEL_V2_YAZMA_AKTIF kapaliyken 409 doner (UI toast gosterir).
 */
Route::get('/v2/personel/{sube?}', function ($sube = null) {
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.personel', ['salonId' => $salonId]);
})->whereNumber('sube');

/*
 | Randevu Takvimi v2 — personel/oda/cihaz/kategori sutunlu gunluk takvim host
 | sayfasi (ayni island deseni). Takvim motoru EventCalendar (MIT, ucretsiz).
 | Veri /api/v2/randevu/takvim; durum aksiyonlari /api/v2/randevu/{id}/* —
 | RANDEVU_V2_YAZMA_AKTIF kapaliyken 409 (UI onizleme/DRY-RUN plani gosterir).
 */
Route::get('/v2/takvim/{sube?}', function ($sube = null) {
    $salonId = (int) ($sube ?: request('sube', 0));

    return view('v2.takvim', ['salonId' => $salonId]);
})->whereNumber('sube');
