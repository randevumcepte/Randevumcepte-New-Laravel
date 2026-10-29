<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Models\Personeller;
use App\Services\Satis\SatisListeServisi;
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
     * GET /api/v2/satislar — satis (adisyon) listesi + ozet. Parametreler query
     * veya gövdeden (input) okunur; eski v1 isimleri de kabul edilir (parite).
     *   salon_id (zorunlu), tarih1, tarih2, tur/adisyonturu (0-3),
     *   musteri_id/musteriid, personel_id, acik_kapali/acikKapali (1 acik/0 kapali),
     *   musteriMi, appBundle, page, per_page.
     */
    public function index(Request $request)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        $salonId = (int) ($request->input('salon_id') ?: $request->input('salonid'));
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
        // (Musteri karti + 'musteri.gecmis_satis_gor' istisnasi: kisit kalkar.)
        $kisit = null;
        $musteriId = $request->input('musteri_id') ?: $request->input('musteriid');
        if (! $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'satis.tum_satis_gor')) {
            $gecmisGor = $musteriId
                && $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'musteri.gecmis_satis_gor');
            if (! $gecmisGor) {
                $pid = Personeller::where('yetkili_id', $user->id)->where('salon_id', $salonId)->value('id');
                if ($pid) {
                    $kisit = (int) $pid;
                }
            }
        }

        $veri = $this->servis->liste($salonId, [
            'tarih1'            => $request->input('tarih1'),
            'tarih2'            => $request->input('tarih2'),
            'adisyonturu'       => $request->input('tur', $request->input('adisyonturu', 0)),
            'musteri_id'        => $musteriId,
            'personel_id'       => $request->input('personel_id'),
            'acik_kapali'       => $request->input('acik_kapali', $request->input('acikKapali')),
            'fatura'            => $request->input('fatura'),
            'musteriMi'         => filter_var($request->input('musteriMi'), FILTER_VALIDATE_BOOLEAN),
            'appBundle'         => $request->input('appBundle'),
            'page'              => $request->input('page'),
            'per_page'          => $request->input('per_page'),
            'kisit_personel_id' => $kisit,
        ]);

        return ApiCevap::basarili($veri);
    }
}
