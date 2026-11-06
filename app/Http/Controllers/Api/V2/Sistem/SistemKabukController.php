<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\SalonKapsami;
use App\Services\SistemYonetim\SistemKabukServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 *   GET /sistem/kabuk        → {kullanici, menu[], rozetler}
 *   GET /sistem/bildirim     → {sayi, liste[]}
 *   GET /sistem/ara?q=       → {salon[], ticket[], ekip[]}
 *   GET /sistem/salon-ara?q= → [{id, salon_adi}]
 */
class SistemKabukController extends SistemTabanController
{
    public function __construct(private SistemKabukServisi $servis, private SalonKapsami $kapsam) {}

    public function kabuk()
    {
        return ApiCevap::basarili($this->servis->acilis($this->yonetici()));
    }

    public function bildirim()
    {
        return ApiCevap::basarili($this->servis->bildirimler($this->yonetici()));
    }

    public function ara(Request $request)
    {
        return ApiCevap::basarili($this->servis->ara((string) $request->query('q', ''), $this->yonetici(), $this->kapsam));
    }

    public function salonAra(Request $request)
    {
        return ApiCevap::basarili($this->servis->salonAra((string) $request->query('q', ''), $this->yonetici(), $this->kapsam));
    }
}
