<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Services\Bildirim\BildirimKutusuFabrikasi;
use App\Services\Form\ArsivListeServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Müşteri uygulaması "Sözleşmelerim" — v1 arsivyukle (musteriMi) karşılığı. v1 kimliksizdi ve
 * istekteki musteri_id ile başkasının belgesini de verirdi. Burada müşteri token'dan gelir:
 * markanın şubelerindeki yalnız kendi kayıtları döner.
 *
 * GET /musteri-uygulama/sozlesmelerim?salon_id=&app_bundle=&sekme=&ara=&page=&per_page=
 */
class MusteriArsivController extends MusteriUygulamaController
{
    public function __construct(private ArsivListeServisi $liste) {}

    public function liste(Request $request)
    {
        return ApiCevap::basarili($this->liste->musteriListesi(
            $this->salonlar($request),
            $this->musteriId(),
            (string) $request->input('sekme', 'tumu'),
            ListeIstegi::istekten($request, 9, ['tarih'], 'tarih'),
        ));
    }

    /** @return array<int,int> */
    private function salonlar(Request $request): array
    {
        $bundle = (string) $request->input('app_bundle', '');
        if ($bundle !== '' && $bundle !== BildirimKutusuFabrikasi::ANA_UYGULAMA) {
            $marka = DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($marka) {
                return $marka;
            }
        }
        $salonId = $request->integer('salon_id');
        if (! $salonId) {
            throw new GecersizIslemException('salon_id zorunlu');
        }

        return [$salonId];
    }
}
