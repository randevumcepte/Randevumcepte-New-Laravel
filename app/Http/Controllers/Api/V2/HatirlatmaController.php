<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Hatirlatma\SalonHatirlatmaServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/** GET /api/v2/hatirlatma-feed?salon_id= → {sayi, hatirlatmalar[]}; kapsam ve rol kuralı serviste. */
class HatirlatmaController extends V2Controller
{
    public function feed(Request $request, SalonHatirlatmaServisi $s)
    {
        return ApiCevap::basarili($s->feed((int) $this->kullanici()->id, $this->salonId($request)));
    }
}
