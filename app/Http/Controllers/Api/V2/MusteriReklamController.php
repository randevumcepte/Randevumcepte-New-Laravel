<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\BildirimReklam\MusteriReklamServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * BİLDİRİM REKLAMLARI v2 — MÜŞTERİ uygulaması (eski /api/v1/reklam/liste + /reklam/kupon-kap).
 * 'auth:api' (users, legacy-passport) arkasında: müşteri kimliği token'dan gelir, gövdedeki
 * user_id YOK SAYILIR (eski uçlar kimliksizdi).
 *
 *   GET  /musteri-uygulama/reklamlar?salon_id= | ?appBundle=   → [kart]  (BildirimReklamSunumu::musteri)
 *   POST /musteri-uygulama/reklamlar/{id}/kupon               → {zaten, kupon}
 */
class MusteriReklamController extends MusteriUygulamaController
{
    public function __construct(private MusteriReklamServisi $servis) {}

    public function liste(Request $request)
    {
        $bundle = $request->input('app_bundle', $request->input('appBundle'));

        return ApiCevap::basarili($this->servis->aktifler($request->integer('salon_id'), $bundle ? (string) $bundle : null, $this->musteriId()));
    }

    public function kuponKap(int $id)
    {
        $sonuc = $this->servis->kuponKap($this->musteriId(), $id);

        return ApiCevap::basarili($sonuc, $sonuc['zaten'] ? 'Kuponun zaten cebinde 👍' : '🎉 Kuponun tanımlandı!', $sonuc['zaten'] ? 200 : 201);
    }
}
