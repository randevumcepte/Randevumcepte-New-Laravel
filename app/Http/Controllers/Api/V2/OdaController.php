<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Ayar\OdaIstegi;
use App\Services\Ayar\OdaServisi;
use App\Services\Ayar\TakvimKaynakYonetimi;
use App\Support\ApiCevap;

/** Ayarlar → Odalar (ortak eylemler KaynakTabanController'da). */
class OdaController extends KaynakTabanController
{
    public function __construct(private OdaServisi $oda) {}

    protected function yonetim(): TakvimKaynakYonetimi
    {
        return $this->oda;
    }

    /** POST /ayar/odalar · PATCH /ayar/odalar/{id}  body: {salon_id, oda_adi, personel_ids[], hizmet_ids[]} */
    public function kaydet(OdaIstegi $request, ?int $id = null)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, $this->sekme());

        return ApiCevap::basarili($this->oda->kaydet($salonId, $yapanId, $id, $request->veri()),
            $id ? 'Oda bilgileri güncellendi' : 'Oda eklendi');
    }
}
