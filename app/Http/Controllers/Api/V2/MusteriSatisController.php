<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Satis\SatisListeServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * GET /musteri-uygulama/satislar — musterinin kendi satislari (eski satislar, musteriMi=true).
 * Musteri id token'dan; markanin (app_bundle) subelerindeki ya da bundle yoksa (salon sitesi) yalniz salon_id'deki
 * kendi adisyonlari. Prim tutari gitmez.
 */
class MusteriSatisController extends MusteriUygulamaController
{
    public function liste(Request $request, SatisListeServisi $servis)
    {
        $g = $request->validate([
            'salon_id'    => ['required', 'integer'],
            // Uygulama: markanin tum subeleri (bundle). Salon sitesi bundle gondermez -> yalniz salon_id.
            'app_bundle'  => ['nullable', 'string', 'max:190'],
            'tarih1'      => ['nullable', 'string', 'max:19'],
            'tarih2'      => ['nullable', 'string', 'max:19'],
            'tur'         => ['nullable', 'integer'],
            'acik_kapali' => ['nullable', 'integer'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ]);

        $sonuc = $servis->liste((int) $g['salon_id'], [
            'mobil'           => true,
            'musteri_gorunumu' => true,
            'musteriMi'       => true,
            'appBundle'       => $g['app_bundle'] ?? null,
            'tarih1'          => $g['tarih1'] ?? null,
            'tarih2'          => $g['tarih2'] ?? null,
            'adisyonturu'     => $g['tur'] ?? 0,
            'musteri_id'      => $this->musteriId(),
            'personel_id'     => null,
            'acik_kapali'     => $g['acik_kapali'] ?? -1,
            'page'            => $g['page'] ?? 1,
            'per_page'        => 20,
        ]);
        // Satir 'islemler' alani yonetici paneli linkleri tasir (HTML): musteri cevabina girmez.
        if (isset($sonuc['data']) && is_array($sonuc['data'])) {
            $sonuc['data'] = array_map(fn ($r) => is_array($r) ? array_diff_key($r, ['islemler' => 1]) : $r, $sonuc['data']);
        }

        return ApiCevap::basarili($sonuc);
    }
}
