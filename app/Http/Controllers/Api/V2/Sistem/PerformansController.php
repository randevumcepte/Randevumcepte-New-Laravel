<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Izleme\PerformansServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/** GET /sistem/performans?gun= + ListeIstegi → {gun, gunler[], genel, data[], ...sayfalama} */
class PerformansController extends SistemTabanController
{
    public function __construct(private PerformansServisi $servis) {}

    public function rapor(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 25, array_keys(PerformansServisi::SIRALAMA), 'ad', 'asc')->dizi();

        return ApiCevap::basarili($this->servis->rapor($request->integer('gun', 30), $liste));
    }
}
