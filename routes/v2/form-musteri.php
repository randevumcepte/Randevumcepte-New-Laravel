<?php

use App\Http\Controllers\Api\V2\MusteriArsivController;
use Illuminate\Support\Facades\Route;

/*
 | MÜŞTERİ SÖZLEŞMELERİ — routes/api.php 'auth:api' grubundan dahil edilir. Müşteri token'dan gelir.
 */
Route::get('/musteri-uygulama/sozlesmelerim', [MusteriArsivController::class, 'liste']);
