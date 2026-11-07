<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Ozet\MobilKarsilastirmaHesap;
use App\Domain\Ozet\MobilOzetHesap;
use App\Services\Ozet\MobilKarsilastirmaServisi;
use App\Services\Ozet\MobilOzetServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Mobil ana ekran — eski v1 /dashboard ve /dashboardKarsilastirma cevaplari (kimliksizdi). Web /ozet uclari degismez.
 *
 *   GET /ozet/mobil?salon_id=                      eski ozetsayfasi alanlari
 *   GET /ozet/mobil-karsilastirma?salon_id=&period= eski dashboardKarsilastirma alanlari
 *
 * Kapi: imzali mobil token, kimlik + salon kapsami, salonun her uyesi (eski mobil karti ekranda Yetki ile gizler). Personel kapsami salondaki
 * rolden cozulur; istemcinin personel_id'si okunmaz, baska personelin cirosu istenemez.
 */
class MobilOzetController extends V2Controller
{
    public function __construct(private MobilOzetServisi $ozet, private MobilKarsilastirmaServisi $karsilastirma) {}

    public function ozet(Request $r)
    {
        $this->mobilOlmali($r);
        [$uid, $salonId] = $this->salonDenetle($r, []);

        return ApiCevap::basarili($this->ozet->ozet($uid, $salonId));
    }

    public function karsilastirma(Request $r)
    {
        $this->mobilOlmali($r);
        [$uid, $salonId] = $this->salonDenetle($r, []);
        $personel = $this->ozet->personel($uid, $salonId);
        $kapsam = (int) $personel->role_id === MobilOzetHesap::PERSONEL_ROLU ? (int) $personel->id : null;

        return ApiCevap::basarili($this->karsilastirma->veri($salonId, MobilKarsilastirmaHesap::donem($r->input('period')), $kapsam));
    }
}
