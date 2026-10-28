<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Tahsilat\AdisyonOkumaServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;

/**
 * Adisyon API v2 controller (ince). Is mantigi servislerde; burada yalnizca
 * kimlik/kapsam/yetki denetimi + servis cagrisi + standart zarf.
 */
class AdisyonController extends Controller
{
    public function __construct(
        private AdisyonOkumaServisi $servis,
        private GuvenlikKapisi $kapi,
    ) {}

    /**
     * GET /api/v2/adisyon/{id}  (auth:isletmeyonetim-api)
     * Salt-okuma: adisyon basligi + kalemler + tahsilatlar + parasal ozet.
     */
    public function goster(int $id)
    {
        // Kimlik: legacy-passport guard'indan isletme yetkilisi.
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        // Adisyon hangi salona ait? (kapsam denetimi icin — kayit yoksa 404)
        $salonId = $this->servis->salonIdGetir($id);
        if (! $salonId) {
            // Kapsam sizintisini onlemek icin kayit yoksa da 404.
            return ApiCevap::hata('Adisyon bulunamadı', 404);
        }

        // Kapsam + yetki (OR-gate: gorme yetkilerinden herhangi biri yeterli —
        // eski adisyonlar() sayfasindaki gate ile birebir).
        $this->kapi->denetle($user->id, $salonId, [
            'satis.tum_satis_gor',
            'satis.adisyon_olustur',
            'satis.tahsilat_al',
        ]);

        return ApiCevap::basarili($this->servis->getir($id));
    }
}
