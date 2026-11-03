<?php

use App\Http\Controllers\Api\V2\RandevuListeController;
use Illuminate\Support\Facades\Route;

/*
 | RANDEVULAR LISTESI v2 — routes/api.php korumali grubundan (auth:isletmeyonetim-api)
 | dahil edilir. Web (/v2/randevular) ve Flutter AYNI uclari kullanir. Satir islemleri
 | (onayla / iptal / duzenle / geldi ...) mevcut /randevu/{id}/* uclarindadir.
 */
Route::get('/randevu/liste', [RandevuListeController::class, 'index']);
Route::get('/randevu/liste/secenekler', [RandevuListeController::class, 'secenekler']);
