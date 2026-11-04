<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Ders\DersRaporServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/** GET /ders/rapor?salon_id=&tarih1=&tarih2= → {tarih1, tarih2, ozet, egitmen[], ders[]} (eski /grup-dersi-rapor). */
class DersRaporController extends DersTabanController
{
    public function rapor(Request $request, DersRaporServisi $servis)
    {
        [, $salonId] = $this->dersSalonu($request, self::OKU);
        $g = $request->validate(['tarih1' => ['nullable', 'date_format:Y-m-d'], 'tarih2' => ['nullable', 'date_format:Y-m-d']]);

        return ApiCevap::basarili($servis->rapor($salonId, $g['tarih1'] ?? date('Y-m-01'), $g['tarih2'] ?? date('Y-m-d')));
    }
}
