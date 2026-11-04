<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Http\Controllers\Controller;
use App\Services\Online\OnlineMusaitlikServisi;
use App\Services\Online\OnlineSecenekServisi;
use App\Services\Online\VitrinServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * HERKESE ACIK isletme vitrini + randevu sihirbazi verisi (mini site + musteri uygulamasi).
 * Musteri kimligi gerektirmez, kisisel veri dondurmez (yorum adlari maskeli).
 *
 *   GET /vitrin/coz?domain=|app_bundle=
 *   GET /vitrin/{salonId}
 *   GET /vitrin/{salonId}/yorumlar?page=
 *   GET /vitrin/{salonId}/randevu-secenekleri
 *   GET /vitrin/{salonId}/personeller?hizmet_id=
 *   GET /vitrin/{salonId}/saatler?tarih=&kalemler[0][hizmet_id]=&kalemler[0][personel_id]=
 */
class VitrinController extends Controller
{
    public function __construct(private VitrinServisi $vitrin, private OnlineSecenekServisi $secenek) {}

    public function coz(Request $request)
    {
        $domain = trim((string) $request->query('domain'));
        $bundle = trim((string) $request->query('app_bundle'));
        if ($domain === '' && $bundle === '') {
            throw new GecersizIslemException('domain ya da app_bundle zorunlu');
        }

        return ApiCevap::basarili($this->vitrin->coz($domain ?: null, $bundle ?: null));
    }

    public function goster(int $salonId)
    {
        return ApiCevap::basarili($this->vitrin->vitrin($salonId));
    }

    public function yorumlar(int $salonId)
    {
        return ApiCevap::basarili($this->vitrin->yorumlar($salonId, auth('api')->id()));
    }

    public function randevuSecenekleri(int $salonId)
    {
        return ApiCevap::basarili($this->secenek->secenekler($salonId));
    }

    public function personeller(Request $request, int $salonId)
    {
        $request->validate(['hizmet_id' => ['required', 'integer']]);

        return ApiCevap::basarili($this->secenek->hizmetPersonelleri($salonId, $request->integer('hizmet_id')));
    }

    public function saatler(Request $request, OnlineMusaitlikServisi $musaitlik, int $salonId)
    {
        $g = $request->validate([
            'tarih'                  => ['required', 'date_format:Y-m-d'],
            'kalemler'               => ['required', 'array', 'min:1'],
            'kalemler.*.hizmet_id'   => ['required', 'integer'],
            'kalemler.*.personel_id' => ['nullable', 'integer'],
        ]);

        return ApiCevap::basarili($musaitlik->saatler($salonId, $g['tarih'], $g['kalemler']));
    }
}
