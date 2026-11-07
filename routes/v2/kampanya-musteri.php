<?php

use App\Http\Controllers\Api\V2\MusteriKampanyaController;
use Illuminate\Support\Facades\Route;

/* Müşteri uygulaması kampanyaları — routes/api.php 'auth:api' grubundan dahil edilir. */
Route::get('/musteri-uygulama/kampanyalar', [MusteriKampanyaController::class, 'liste']);
