<?php

use App\Http\Controllers\Api\V2\PatronAsistanController;
use App\Http\Controllers\Api\V2\SeslendirmeController;
use Illuminate\Support\Facades\Route;

/*
 | YARDIMCI UCLAR — routes/api.php korumali grubundan dahil edilir. Patron asistani (rapor.ciro_kar_gor)
 | ve metin seslendirme (salon kapsami + ekran yetkisi + kota). Ses dosyasi routes/v2/ses-dosyasi.php'de.
 */
Route::post('/patron-asistan/sor', [PatronAsistanController::class, 'sor']);
Route::post('/patron-asistan/uygula', [PatronAsistanController::class, 'uygula']);
Route::post('/seslendir', [SeslendirmeController::class, 'uret'])->middleware('throttle:30,1');
