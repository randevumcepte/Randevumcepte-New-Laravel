<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Izleme\RiskServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/** GET /sistem/risk + ListeIstegi (sirala=salon|skor|durum|mt). Destek rolunde yalniz kendi salonlari. */
class RiskController extends SistemTabanController
{
    public function __construct(private RiskServisi $servis) {}

    public function liste(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 50, array_keys(RiskServisi::SIRALAMA), 'skor', 'asc')->dizi();

        return ApiCevap::basarili($this->servis->listele($this->yonetici(), $liste));
    }
}
