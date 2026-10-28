<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Models\Personeller;
use App\Services\Tahsilat\SatisListeServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Satis (adisyon listesi) API v2 — ince controller. KVKK: kimlik + kapsam + yetki.
 */
class SatisController extends Controller
{
    public function __construct(
        private SatisListeServisi $servis,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
    ) {}

    /**
     * GET /api/v2/satislar?salon_id=&acik_kapali=&tarih1=&tarih2=&page=&per_page=
     */
    public function index(Request $request)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        $salonId = (int) $request->query('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        // Kapsam + görüntüleme yetkisi (OR-gate — eski satis listesi gate'i).
        $this->kapi->denetle($user->id, $salonId, [
            'satis.tum_satis_gor',
            'satis.adisyon_olustur',
            'satis.tahsilat_al',
        ]);

        // Personel kapsami: 'satis.tum_satis_gor' yoksa YALNIZCA kendi satislari.
        $kisit = null;
        if (! $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'satis.tum_satis_gor')) {
            $pid = Personeller::where('yetkili_id', $user->id)->where('salon_id', $salonId)->value('id');
            if ($pid) {
                $kisit = (int) $pid;
            }
        }

        $veri = $this->servis->liste($salonId, [
            'tarih1'            => $request->query('tarih1'),
            'tarih2'            => $request->query('tarih2'),
            'acik_kapali'       => $request->query('acik_kapali'),
            'page'              => $request->query('page'),
            'per_page'          => $request->query('per_page'),
            'kisit_personel_id' => $kisit,
        ]);

        return ApiCevap::basarili($veri);
    }
}
