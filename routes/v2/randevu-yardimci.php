<?php

use App\Http\Controllers\Api\V2\RandevuNotController;
use App\Http\Controllers\Api\V2\SesliRandevuController;
use Illuminate\Support\Facades\Route;

/*
 | RANDEVU YARDIMCI UCLARI — routes/api.php korumali grubundan dahil edilir.
 | Islem sonrasi not (musteri.not_yaz) ve sesli randevunun musaitlik sorgulari (salon uyesi).
 */
Route::patch('/randevu/{id}/sonrasi-not', [RandevuNotController::class, 'kaydet'])->whereNumber('id');
Route::get('/randevu/sesli/musaitlik', [SesliRandevuController::class, 'musaitlik']);
Route::get('/randevu/sesli/takvim-durumu', [SesliRandevuController::class, 'takvimDurumu']);
