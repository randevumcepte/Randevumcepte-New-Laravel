<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Santral\SoftphoneServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * WebRTC softphone (panel header'i).
 *
 * GET /api/v2/softphone?salon_id=                 → kurulum | null (softphone yok)
 * GET /api/v2/softphone/dakika?salon_id=          → {kalan: ?float}
 * GET /api/v2/softphone/arayan?salon_id=&numara=  → {ad: ?string}
 */
class SoftphoneController extends V2Controller
{
    public function __construct(private SoftphoneServisi $servis) {}

    public function goster(Request $request)
    {
        return ApiCevap::basarili($this->servis->yapilandirma($this->uid(), $this->salonId($request)));
    }

    public function dakika(Request $request)
    {
        return ApiCevap::basarili(['kalan' => $this->servis->kalanDakika($this->uid(), $this->salonId($request))]);
    }

    public function arayan(Request $request)
    {
        $numara = mb_substr(trim((string) $request->input('numara', '')), 0, 32);

        return ApiCevap::basarili(['ad' => $this->servis->arayanAdi($this->uid(), $this->salonId($request), $numara)]);
    }

    private function uid(): int
    {
        return (int) $this->kullanici()->id;
    }
}
