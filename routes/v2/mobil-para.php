<?php

use App\Http\Controllers\Api\V2\TahsilatMobilController as C;
use Illuminate\Support\Facades\Route;

/*
 | Eski mobilin para uclari (tahsilat alani): yalniz imzali mobil token + mobil=1. Web bu uclari kullanmaz.
 */
Route::prefix('mobil-para')->group(function () {
    Route::post('/tahsilat', [C::class, 'tahsilat']);
    Route::post('/taksit', [C::class, 'taksit']);
    Route::get('/alacaklar', [C::class, 'alacaklar']);
    Route::get('/alacaklar-v2', [C::class, 'alacaklarV2']);
    Route::get('/tum-alacaklar', [C::class, 'tumAlacaklar']);
    Route::get('/senetler', [C::class, 'senetler']);
    // Senet yazma uclari (ekle, ode, vade guncelle) arsivde: arsiv/senet/README.md. Liste okumasi Alacaklar ekranindaki sekme icin kalir.
    Route::get('/harici-kalemler', [C::class, 'hariciKalemler']);
    Route::post('/harici', [C::class, 'hariciEkle']);
    Route::get('/kampanya-kodlari', [C::class, 'kampanyaKodlari']);
    Route::post('/kampanya-kod-kullan', [C::class, 'kampanyaKodKullan']);
    Route::get('/kampanya-kod-durum', [C::class, 'kampanyaKodDurum']);
    Route::post('/randevu/{id}/tahsilat', [C::class, 'randevuTahsilat'])->whereNumber('id');
    Route::post('/ongorusme/{id}/satis', [C::class, 'ongorusmeSatis'])->whereNumber('id');
    Route::post('/hizli-satis', [C::class, 'hizliSatis']);
    Route::get('/cagri-kalemler', [C::class, 'cagriKalemler']);
    Route::post('/cagri-hizli-satis', [C::class, 'cagriHizliSatis']);
});
