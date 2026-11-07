<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Services\Kampanya\SaatBosluguKampanyaSorgusu;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * GET /musteri-uygulama/kampanyalar?salon_id= — müşteri ana sayfasındaki "Kampanya ve Duyurular".
 * Müşteri token'ı zorunludur. Reklam kartlarıyla aynı şekilde salonun tanıtım verisidir; beyaz liste
 * dışında alan dönmez.
 */
class MusteriKampanyaController extends MusteriUygulamaController
{
    public function liste(Request $request, SaatBosluguKampanyaSorgusu $sorgu)
    {
        $this->musteriId();
        $salonId = $request->integer('salon_id');
        if ($salonId <= 0) {
            throw new GecersizIslemException('salon_id zorunlu');
        }

        return ApiCevap::basarili($sorgu->musteriye($salonId));
    }
}
