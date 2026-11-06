<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Salon\SalonAktarimServisi;
use App\Services\SistemYonetim\Salon\SalonDetayServisi;
use App\Services\SistemYonetim\Salon\SalonListeServisi;
use App\Services\SistemYonetim\Salon\SalonSecenekServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 *   GET /sistem/salon?durum=&tip=&mt= + ListeIstegi → {data[], sayilar{tumu,aktif,demo}, sayfalama}
 *   GET /sistem/salon/secenekler  GET /sistem/salon/{id}
 *   GET /sistem/salon/csv  GET /sistem/salon/{id}/musteriler/csv
 */
class SalonController extends SistemTabanController
{
    public function liste(Request $request, SalonListeServisi $servis)
    {
        $liste = ListeIstegi::istekten($request, 100, array_keys(SalonListeServisi::SIRALAMA), 'id', 'desc');

        return ApiCevap::basarili($servis->listele($liste->dizi(), $this->filtre($request), $this->yonetici()));
    }

    public function secenekler(SalonSecenekServisi $servis)
    {
        return ApiCevap::basarili($servis->hepsi($this->yonetici()->rol()));
    }

    public function detay(int $id, SalonDetayServisi $servis)
    {
        return ApiCevap::basarili($servis->detay($this->salon($id, SalonDetayServisi::KOLONLAR), $this->yonetici()));
    }

    public function csv(Request $request, SalonAktarimServisi $servis)
    {
        return $servis->salonlar(ListeIstegi::istekten($request)->ara, $this->filtre($request), $this->yonetici());
    }

    public function musterilerCsv(int $id, SalonAktarimServisi $servis)
    {
        return $servis->musteriler($this->salon($id, ['id', 'salon_adi']));
    }

    private function filtre(Request $request): array
    {
        return $this->alanlariDogrula($request, [
            'durum' => ['nullable', 'in:hepsi,aktif,askida'],
            'tip'   => ['nullable', 'in:tumu,aktif,demo'],
            'mt'    => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
