<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Kampanya\SaatBosluguKampanyaSorgusu;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * GET /kampanya/saat-boslugu?salon_id= — takvim şeridindeki aktif saat boşluğu kampanyaları.
 * Takvimi gören her rol okur, bu yüzden yalnız salon kapsamı denetlenir.
 */
class KampanyaSaatBosluguController extends V2Controller
{
    public function aktifler(Request $request, SaatBosluguKampanyaSorgusu $sorgu)
    {
        [, $salonId] = $this->salonDenetle($request, []);

        return ApiCevap::basarili($sorgu->aktifler($salonId));
    }
}
