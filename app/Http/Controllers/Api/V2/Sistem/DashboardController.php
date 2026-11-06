<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Izleme\DashboardServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 *   GET /sistem/dashboard            → {metrikler, son_aktiviteler[], bekleyen_talepler[], son_girisler[], trend[], grafik_gunleri[], grafik_varsayilan}
 *   GET /sistem/dashboard/grafik?gun= → {tarihler[], salon[], randevu[], ticket[], kategori_dagilim, durum_dagilim}
 */
class DashboardController extends SistemTabanController
{
    public function __construct(private DashboardServisi $servis) {}

    public function ozet()
    {
        return ApiCevap::basarili($this->servis->ozet());
    }

    public function grafik(Request $request)
    {
        return ApiCevap::basarili($this->servis->grafik($request->integer('gun', 30)));
    }
}
