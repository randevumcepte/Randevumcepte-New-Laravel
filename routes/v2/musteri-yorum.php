<?php

use App\Http\Controllers\Api\V2\MusteriYorumYonetimController;
use Illuminate\Support\Facades\Route;

/*
 | MUSTERI YORUMU MODERASYONU (Apple 1.2 UGC) — routes/api.php korumali grubundan dahil edilir. Eski
 | musteri-yorumlari-admin ve musteri-yorumu-sil kimliksizdi; kimlik ve salon kapsami zorunludur.
 */
Route::get('/musteri-yorum', [MusteriYorumYonetimController::class, 'liste']);
Route::delete('/musteri-yorum/{id}', [MusteriYorumYonetimController::class, 'sil'])->whereNumber('id');
