<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Satis\KatalogServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Katalog API v2 — satış ekranı ekle-dropdownları (salt okuma).
 * KVKK: kimlik + kapsam + görme/satış yetkisi.
 */
class KatalogController extends Controller
{
    public function __construct(
        private KatalogServisi $servis,
        private GuvenlikKapisi $kapi,
    ) {}

    /** GET /api/v2/katalog?salon_id= */
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

        // Katalog görme: satış/adisyon ile ilgili herhangi bir yetki yeterli (OR-gate).
        $this->kapi->denetle($user->id, $salonId, [
            'satis.adisyon_olustur',
            'satis.tahsilat_al',
            'satis.tum_satis_gor',
        ]);

        return ApiCevap::basarili($this->servis->listele($salonId));
    }
}
