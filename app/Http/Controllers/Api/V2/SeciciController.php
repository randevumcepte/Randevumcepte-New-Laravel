<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Secici\SeciciServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Ortak secim listeleri: salona bagli her kullanici okur, ozel yetki aranmaz.
 *   GET /secici/hizmetler|cihazlar|odalar|salon ?salon_id=
 *   GET /secici/sehirler
 */
class SeciciController extends V2Controller
{
    public function __construct(private SeciciServisi $secici, private GuvenlikKapisi $kapi) {}

    public function hizmetler(Request $request)
    {
        return ApiCevap::basarili($this->secici->hizmetler($this->salon($request)));
    }

    public function cihazlar(Request $request)
    {
        return ApiCevap::basarili($this->secici->cihazlar($this->salon($request)));
    }

    public function odalar(Request $request)
    {
        return ApiCevap::basarili($this->secici->odalar($this->salon($request)));
    }

    public function salonBilgi(Request $request)
    {
        return ApiCevap::basarili($this->secici->salon($this->salon($request)));
    }

    public function sehirler()
    {
        return ApiCevap::basarili($this->secici->sehirler());
    }

    private function salon(Request $request): int
    {
        $salonId = $this->salonId($request);
        $this->kapi->kapsamDenetle((int) $this->kullanici()->id, $salonId);

        return $salonId;
    }
}
