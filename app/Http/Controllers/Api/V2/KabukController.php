<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Panel\PanelKabukServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Panel kabuğu API v2 — sol menü + header için gereken minimum veri.
 *
 * GET /api/v2/kabuk?salon_id=  (auth:isletmeyonetim-api)
 *   salon_id verilmezse kullanıcının erişebildiği İLK salon kullanılır. Kapsam
 *   denetimi: salon kullanıcının erişebildiği salonlardan biri olmalı (yoksa 403).
 *   Yetki: menü ZATEN kullanıcının yetkilerine göre filtrelenir; ayrı anahtar
 *   gerekmez (kabuk herkese açık ama yalnızca kendi kapsamındaki salon için).
 */
class KabukController extends V2Controller
{
    public function __construct(private PanelKabukServisi $servis) {}

    public function goster(Request $request)
    {
        return ApiCevap::basarili($this->servis->kabuk((int) $this->kullanici()->id, $this->baglamSalonu($request)));
    }
}
