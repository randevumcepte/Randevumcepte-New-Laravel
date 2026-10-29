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
