<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Ayar\SubeServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/** Ayarlar → Subeler (salt okuma; yetki ayar.sube_yonet). Yazma eski panelde — bkz. SubeServisi. */
class SubeController extends AyarTabanController
{
    public function __construct(private SubeServisi $sube) {}

    /** GET /api/v2/ayar/subeler?salon_id=&ara=&page=&per_page=&sirala=salon_adi|uyelik_bitis_tarihi&yon= */
    public function liste(Request $request)
    {
        $salonId = $this->okumaSalonu($request, 'subeler');
        $liste = ListeIstegi::istekten($request, 25, ['salon_adi', 'uyelik_bitis_tarihi']);

        return ApiCevap::basarili($this->sube->listele((int) $this->kullanici()->id, $salonId, $liste->dizi()));
    }
}
