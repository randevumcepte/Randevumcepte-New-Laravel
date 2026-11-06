<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Izleme\GuvenlikLogServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 *   GET /sistem/guvenlik/girisler      + ListeIstegi (sirala=zaman|email|durum|ip)
 *   GET /sistem/guvenlik/impersonation + ListeIstegi (sirala=personel|salon|baslangic|ip)
 */
class GuvenlikLogController extends SistemTabanController
{
    public function __construct(private GuvenlikLogServisi $servis) {}

    public function girisler(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 50, array_keys(GuvenlikLogServisi::GIRIS_SIRALAMA), 'zaman', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->girisler($liste));
    }

    public function impersonation(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 50, array_keys(GuvenlikLogServisi::IMPERSONATION_SIRALAMA), 'baslangic', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->impersonation($liste));
    }
}
