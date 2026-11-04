<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Studyo\StudyoPolitikaServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * GET /studyo/politika?salon_id= — isletmenin stüdyo kurallari (kapsam yeter, yetki aranmaz):
 * fiyat_gizli, odeme_modu, gizli_menuler, gizli_ozellikler, yayin_maks_hafta, ... Flutter
 * bayragi yorumlamak yerine bu sozlesmeyi okur.
 */
class StudyoController extends V2Controller
{
    public function politika(Request $request, StudyoPolitikaServisi $servis)
    {
        [, $salonId] = $this->salonDenetle($request, []);

        return ApiCevap::basarili($servis->sozlesme($salonId));
    }
}
