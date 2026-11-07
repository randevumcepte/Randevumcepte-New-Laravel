<?php

use App\Http\Controllers\Api\V2\FormPdfController;
use Illuminate\Support\Facades\Route;

/*
 | BOŞ FORM PDF — harici tarayıcıda açılır, token taşıyamaz. Linki yalnız yetkili kullanıcı üretir,
 | imzalıdır ve kısa ömürlüdür. İçerikte kişisel veri yoktur (salon adı ve form soruları).
 */
Route::get('/form/sablon/{id}/bos-pdf', [FormPdfController::class, 'bos'])
    ->whereNumber('id')
    ->middleware('signed:relative')
    ->name('v2.form.bos-pdf');
