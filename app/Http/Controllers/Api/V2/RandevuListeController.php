<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Randevu\RandevuZamani;
use App\Exceptions\GecersizIslemException;
use App\Services\Randevu\RandevuAyrintiSunumu;
use App\Services\Randevu\RandevuListeServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Randevular LISTESI (eski /isletmeyonetim/randevular-liste + randevulistefiltre) — ince
 * controller. Eski ekran gibi ozel yetki istemez (salon kapsami yeter); personel kapsami
 * ve satir yetkileri RandevuListeServisi::kapsam (musteri karti ile ayni kural).
 *   GET /api/v2/randevu/liste             ?salon_id&donem&baslangic&bitis&kaynak&durum&hizmet_id&musteri_id&personel_id&cihaz_id&ayrinti + ListeIstegi
 *   GET /api/v2/randevu/liste/secenekler  ?salon_id  (zaman / durum / kaynak / hizmet filtreleri)
 */
class RandevuListeController extends V2Controller
{
    public function __construct(private RandevuListeServisi $liste, private RandevuAyrintiSunumu $sunum, private GuvenlikKapisi $kapi) {}

    public function index(Request $request)
    {
        [$uid, $salonId] = $this->salon($request);
        $k = $this->liste->kapsam($uid, $salonId);
        $zaman = RandevuZamani::coz($request->input('donem'), $request->input('baslangic'), $request->input('bitis'));

        $sonuc = $this->liste->listele($salonId, [
            'kisit_personel_id' => $k['kisit_personel_id'],
            'zaman'             => $zaman,
            'kaynak'            => $request->input('kaynak'),
            'durum'             => $request->input('durum'),
            'hizmet_id'         => $request->integer('hizmet_id') ?: null,
            'musteri_id'        => $request->integer('musteri_id') ?: null,
            'personel_id'       => $request->integer('personel_id') ?: null,
            'cihaz_id'          => $request->integer('cihaz_id') ?: null,
        ], ListeIstegi::istekten($request, 25, RandevuListeServisi::SIRALANABILIR, 'tarih', 'desc'), $k['yetki']);

        if ($request->boolean('ayrinti')) {
            $sonuc['data'] = $this->sunum->listeyeEkle($sonuc['data'], $uid, $salonId);
        }

        return ApiCevap::basarili($sonuc + ['donem' => $zaman]);
    }

    public function secenekler(Request $request)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->liste->secenekler($salonId));
    }

    /** @return array{0:int,1:int} [yetkili id, salon id] — yalniz salon kapsami (eski ekranla ayni). */
    private function salon(Request $request): array
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = $request->integer('salon_id');
        if (! $salonId) {
            throw new GecersizIslemException('salon_id zorunlu');
        }
        $this->kapi->kapsamDenetle($uid, $salonId);

        return [$uid, $salonId];
    }
}
