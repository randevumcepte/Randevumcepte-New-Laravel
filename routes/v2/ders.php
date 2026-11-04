<?php

use App\Http\Controllers\Api\V2\DersKatilimciController;
use App\Http\Controllers\Api\V2\DersOturumController;
use App\Http\Controllers\Api\V2\DersProgramController;
use App\Http\Controllers\Api\V2\DersRaporController;
use App\Http\Controllers\Api\V2\DersTekrarliController;
use App\Http\Controllers\Api\V2\StudyoController;
use Illuminate\Support\Facades\Route;

/*
 | STUDYO + GRUP DERSI v2 — routes/api.php korumali grubundan (auth:isletmeyonetim-api) dahil
 | edilir. Kapsam + yetki + modul kapisi DersTabanController'da. Yazmalar DERS_V2_YAZMA_AKTIF
 | arkasinda (kapaliyken 409). Web paneli (/v2/ders-programi, /v2/grup-dersi-raporu, takvim)
 | ve Flutter AYNI uclari kullanir; v1 → v2 esleme docs/strangler-studyo.md.
 */
Route::get('/studyo/politika', [StudyoController::class, 'politika']);

Route::prefix('ders')->group(function () {
    Route::get('/program', [DersProgramController::class, 'program']);
    Route::post('/sablon', [DersProgramController::class, 'sablonEkle']);
    Route::patch('/sablon/{id}', [DersProgramController::class, 'sablonGuncelle'])->whereNumber('id');
    Route::delete('/sablon/{id}', [DersProgramController::class, 'sablonSil'])->whereNumber('id');
    Route::post('/yayinla', [DersProgramController::class, 'yayinla']);

    Route::get('/oturum', [DersOturumController::class, 'liste']);
    Route::post('/oturum', [DersOturumController::class, 'ekle']);
    Route::get('/oturum/{id}', [DersOturumController::class, 'goster'])->whereNumber('id');
    Route::patch('/oturum/{id}', [DersOturumController::class, 'guncelle'])->whereNumber('id');
    Route::delete('/oturum/{id}', [DersOturumController::class, 'iptal'])->whereNumber('id');
    Route::post('/oturum/{id}/katilimci', [DersKatilimciController::class, 'ekle'])->whereNumber('id');

    Route::delete('/katilimci/{id}', [DersKatilimciController::class, 'cikar'])->whereNumber('id');
    Route::patch('/katilimci/{id}/durum', [DersKatilimciController::class, 'durum'])->whereNumber('id');

    Route::get('/musteri-ara', [DersOturumController::class, 'musteriAra']);
    Route::get('/musteri/{id}/dersler', [DersOturumController::class, 'musteriDersleri'])->whereNumber('id');

    Route::get('/tekrarli', [DersTekrarliController::class, 'liste']);
    Route::get('/tekrarli/kaynaklar', [DersTekrarliController::class, 'kaynaklar']);
    Route::post('/tekrarli', [DersTekrarliController::class, 'ekle']);
    Route::patch('/tekrarli/{id}', [DersTekrarliController::class, 'guncelle'])->whereNumber('id');
    Route::delete('/tekrarli/{id}', [DersTekrarliController::class, 'sil'])->whereNumber('id');

    Route::get('/rapor', [DersRaporController::class, 'rapor']);
});
