<?php

use App\Http\Controllers\Api\V2\SeslendirmeController;
use Illuminate\Support\Facades\Route;

/*
 | SES DOSYASI — token yerine sureli imza: ses calar bilesen Authorization basligi gonderemez, adres yalniz
 | token'li POST /seslendir cevabinda uretilir. Goreli imza: proxy arkasinda sema degisse de gecerli kalir.
 */
Route::get('/ses/{ad}', [SeslendirmeController::class, 'ses'])
    ->where('ad', '[a-f0-9]{32}\.mp3')
    ->middleware('signed:relative')
    ->name('v2.ses');
