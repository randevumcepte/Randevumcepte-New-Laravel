<?php

use App\Http\Controllers\Api\V2\MusteriSatisController;
use Illuminate\Support\Facades\Route;

/* MUSTERI SATISLARI — routes/api.php 'auth:api' grubundan dahil edilir; kimlik token'dan. */
Route::get('/musteri-uygulama/satislar', [MusteriSatisController::class, 'liste']);
