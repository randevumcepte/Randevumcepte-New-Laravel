<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\MusteriHesap\MusteriCarkServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Carkifelek ve puan odulleri, musteri uygulamasi ('auth:api'). Kimlik token'dan; govdedeki user_id yok sayilir.
 *
 *   GET  /musteri-uygulama/cark?salon_id=                     POST /musteri-uygulama/cark/cevir {salon_id, app_bundle?}
 *   GET  /musteri-uygulama/cark/odullerim?app_bundle=         GET  /musteri-uygulama/cark/puan-odulleri?app_bundle=&salon_id=
 *   POST /musteri-uygulama/cark/puan-odulleri/{odulId}/talep {salon_id, app_bundle?}
 */
class MusteriCarkController extends MusteriUygulamaController
{
    public function __construct(private MusteriCarkServisi $servis) {}

    public function durum(Request $request)
    {
        $request->validate(['salon_id' => ['required', 'integer']]);

        return ApiCevap::basarili($this->servis->durum($this->musteriId(), $request->integer('salon_id')));
    }

    public function cevir(Request $request)
    {
        $request->validate(['salon_id' => ['required', 'integer'], 'app_bundle' => ['nullable', 'string', 'max:190']]);

        return ApiCevap::basarili($this->servis->cevir($this->musteriId(), $request->integer('salon_id')));
    }

    public function odullerim(Request $request)
    {
        return ApiCevap::basarili($this->servis->odullerim($this->musteriId(), $request->input('app_bundle') ?: null));
    }

    public function puanOdullerim(Request $request)
    {
        return ApiCevap::basarili($this->servis->puanOdullerim($this->musteriId(), $request->input('app_bundle') ?: null, $request->integer('salon_id')));
    }

    public function puanOdulTalep(Request $request, int $odulId)
    {
        $request->validate(['salon_id' => ['required', 'integer'], 'app_bundle' => ['nullable', 'string', 'max:190']]);

        return ApiCevap::basarili($this->servis->puanOdulTalep($this->musteriId(), $request->integer('salon_id'), $odulId));
    }
}
