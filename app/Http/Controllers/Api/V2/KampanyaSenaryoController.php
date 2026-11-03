<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Kampanya\Kanal;
use App\Http\Requests\Kampanya\SenaryoIstegi;
use App\Services\Kampanya\SenaryoServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * SENARYO SİHİRBAZI v2 (eski /isletmeyonetim/senaryo-*). Hazır senaryolar + adım/aksiyon sözlüğü
 * /kampanya/form-verisi içinde (senaryo.*).
 *
 *   GET    /kampanya/senaryo?salon_id=&kanal=     sistem + salon senaryoları
 *   GET    /kampanya/senaryo/{id}?salon_id=
 *   POST   /kampanya/senaryo                       {salon_id, ad, senaryo_tipi, gorev_turu, adimlar{}, aksiyonlar{}}
 *   PATCH  /kampanya/senaryo/{id}                  (yalnız salonun kendi senaryosu)
 *   DELETE /kampanya/senaryo/{id}?salon_id=
 *   POST   /kampanya/senaryo/onizle                {salon_id, adimlar, aksiyonlar, gorev_turu} → {diyalog[], tek_metin}
 */
class KampanyaSenaryoController extends KampanyaTabanController
{
    public function __construct(private SenaryoServisi $senaryo) {}

    public function liste(Request $request)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->senaryo->liste($salonId, Kanal::degerden($request->input('kanal'))));
    }

    public function goster(Request $request, int $id)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->senaryo->getir($salonId, $id));
    }

    public function ekle(SenaryoIstegi $request)
    {
        [$uid, $salonId] = $this->salon($request);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->senaryo->kaydet($salonId, null, $request->validated(), $uid), 'Senaryo kaydedildi', 201);
    }

    public function guncelle(SenaryoIstegi $request, int $id)
    {
        [$uid, $salonId] = $this->salon($request);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->senaryo->kaydet($salonId, $id, $request->validated(), $uid), 'Senaryo güncellendi');
    }

    public function sil(Request $request, int $id)
    {
        [$uid, $salonId] = $this->salon($request);
        $this->yazmaAcikMi();
        $this->senaryo->sil($salonId, $id, $uid);

        return ApiCevap::basarili(['id' => $id], 'Senaryo silindi');
    }

    public function onizle(SenaryoIstegi $request)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->senaryo->onizle($salonId, $request->validated()));
    }
}
