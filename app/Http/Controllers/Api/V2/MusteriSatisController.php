<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Satis\SatisListeServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * GET /musteri-uygulama/satislar — musterinin kendi satislari (eski satislar, musteriMi=true).
 * Musteri id token'dan; markanin (app_bundle) subelerindeki kendi adisyonlari. Prim tutari gitmez.
 */
class MusteriSatisController extends MusteriUygulamaController
{
    public function liste(Request $request, SatisListeServisi $servis)
    {
        $g = $request->validate([
            'salon_id'    => ['required', 'integer'],
            'app_bundle'  => ['required', 'string', 'max:190'],
            'tarih1'      => ['nullable', 'string', 'max:19'],
            'tarih2'      => ['nullable', 'string', 'max:19'],
            'tur'         => ['nullable', 'integer'],
            'acik_kapali' => ['nullable', 'integer'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ]);

        return ApiCevap::basarili($servis->liste((int) $g['salon_id'], [
            'mobil'           => true,
            'musteri_gorunumu' => true,
            'musteriMi'       => true,
            'appBundle'       => $g['app_bundle'],
            'tarih1'          => $g['tarih1'] ?? null,
            'tarih2'          => $g['tarih2'] ?? null,
            'adisyonturu'     => $g['tur'] ?? 0,
            'musteri_id'      => $this->musteriId(),
            'personel_id'     => null,
            'acik_kapali'     => $g['acik_kapali'] ?? -1,
            'page'            => $g['page'] ?? 1,
            'per_page'        => 20,
        ]));
    }
}
