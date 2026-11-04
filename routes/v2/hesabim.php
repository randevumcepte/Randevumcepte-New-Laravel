<?php

use App\Http\Controllers\Api\V2\HesabimController;
use App\Http\Controllers\Api\V2\ProfilController;
use Illuminate\Support\Facades\Route;

/*
 | PROFİL + HESABIM v2 — routes/api.php korumalı grubundan (auth:isletmeyonetim-api) dahil edilir.
 | Web (/v2/profil, /v2/hesabim) ve Flutter AYNI uçları kullanır. Kimlik/kapsam controller'da.
 */
Route::get('/profil', [ProfilController::class, 'goster']);
Route::put('/profil', [ProfilController::class, 'guncelle']);
Route::post('/profil/resim', [ProfilController::class, 'resim']);

Route::get('/hesabim', [HesabimController::class, 'goster']);
Route::put('/hesabim/fatura-bilgisi', [HesabimController::class, 'faturaBilgisi']);
