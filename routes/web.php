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
