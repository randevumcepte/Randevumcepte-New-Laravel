<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Ayar\CihazIstegi;
use App\Services\Ayar\CihazServisi;
use App\Services\Ayar\TakvimKaynakYonetimi;
use App\Support\ApiCevap;

/** Ayarlar → Cihazlar (ortak eylemler KaynakTabanController'da). */
class CihazController extends KaynakTabanController
{
    public function __construct(private CihazServisi $cihaz) {}

    protected function yonetim(): TakvimKaynakYonetimi
    {
        return $this->cihaz;
    }

    /** POST /ayar/cihazlar · PATCH /ayar/cihazlar/{id}  body: {salon_id, cihaz_adi, gunler[7]} */
    public function kaydet(CihazIstegi $request, ?int $id = null)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, $this->sekme());

        return ApiCevap::basarili($this->cihaz->kaydet($salonId, $yapanId, $id, $request->veri()),
            $id ? 'Cihaz güncellendi' : 'Cihaz eklendi');
    }
}
