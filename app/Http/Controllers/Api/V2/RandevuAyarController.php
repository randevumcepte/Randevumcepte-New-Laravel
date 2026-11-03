<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Ayar\OnlineSaatIstegi;
use App\Http\Requests\Ayar\RandevuAyarIstegi;
use App\Services\Ayar\OnlineSaatServisi;
use App\Services\Ayar\RandevuAyarServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Ayarlar → Randevu Ayarlari + Online Randevu Saatleri (yetki: randevu.online_ayar).
 * Flutter randevuayarlari / takvim renk / online_randevu_saatleri ekranlari ile ayni uclar.
 */
class RandevuAyarController extends AyarTabanController
{
    public function __construct(
        private RandevuAyarServisi $randevu,
        private OnlineSaatServisi $online,
    ) {}

    /** GET /api/v2/ayar/randevu?salon_id= — ayarlar + secenekler (aralik, takvim turleri, palet) */
    public function randevu(Request $request)
    {
        return ApiCevap::basarili($this->randevu->getir($this->okumaSalonu($request, 'randevu')));
    }

    /** PATCH /api/v2/ayar/randevu — kismi (gonderilen alanlar) */
    public function randevuKaydet(RandevuAyarIstegi $request)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, 'randevu');

        return ApiCevap::basarili($this->randevu->kaydet($salonId, $yapanId, $request->veri()), 'Randevu ayarları başarıyla kaydedildi');
    }

    /** GET /api/v2/ayar/online-saatler?salon_id= — v1 online_randevu_saatleri bicimi */
    public function onlineSaatler(Request $request)
    {
        return ApiCevap::basarili($this->online->getir($this->okumaSalonu($request, 'online_saatler')));
    }

    /** PUT /api/v2/ayar/online-saatler  body: {salon_id, kisitlama_aktif, gunluk_limit, gunler{gun:[slot]}, istisnalar[]} */
    public function onlineSaatKaydet(OnlineSaatIstegi $request)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, 'online_saatler');

        return ApiCevap::basarili($this->online->kaydet($salonId, $yapanId, $request->veri()), 'Online randevu saatleri başarıyla kaydedildi');
    }
}
