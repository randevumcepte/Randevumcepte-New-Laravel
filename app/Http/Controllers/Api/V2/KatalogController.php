<?php

namespace App\Http\Controllers\Api\V2;

use App\Models\Salonlar;
use App\Services\Satis\KatalogServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Katalog API v2 — satış ekranı ekle-dropdownları (salt okuma).
 * KVKK: kimlik + kapsam + görme/satış yetkisi.
 */
class KatalogController extends V2Controller
{
    public function __construct(
        private KatalogServisi $servis,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
    ) {}

    /** GET /api/v2/katalog?salon_id= */
    public function index(Request $request)
    {
        $user = $this->kullanici();

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

        // fiyat_degistir: ekranlar elle fiyat alanını buna göre açar (kural sunucuda da denetlenir).
        return ApiCevap::basarili($this->servis->listele($salonId) + [
            'fiyat_degistir' => $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'satis.indirim_uygula'),
            'studyo_modu'    => Salonlar::studyoModu($salonId), // MODEL B: fiyat gizli, ikili ödeme
        ]);
    }
}
