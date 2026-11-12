<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Services\Randevu\RandevuFormServisi;
use App\Services\Randevu\RandevuMobilFormServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use App\Support\Yetki\IslemYetkileri;
use Illuminate\Http\Request;

/**
 * Randevu formu veri uclari (Faz 3b-2) — salt okuma.
 *   GET /api/v2/randevu/form-verisi?salon_id=&randevu_id=   (secenekler + duzenleme on doldurmasi)
 *   GET /api/v2/randevu/musteri-ara?salon_id=&q=            (portfoy aramasi, maskeli telefon)
 *   GET /api/v2/randevu/musteri-paketleri?salon_id=&user_id= (kalan seansli paket/hizmet satislari)
 * Yetki: randevu.olustur VEYA randevu.duzenle_iptal. Telefon: musteri.telefon_gor.
 * form-verisi?mobil=1: uygulama formlarinin alanlari (RandevuMobilFormServisi). Yetki ve telefon maskesi web ile
 * AYNI (2026-11-12); musteri listesi web aramasi gibi salonun aktif portfoyu, telefon musteri.telefon_gor ile.
 */
class RandevuFormController extends V2Controller
{
    public function __construct(
        private RandevuFormServisi $servis,
        private RandevuMobilFormServisi $mobilForm,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
    ) {}

    public function formVerisi(Request $request)
    {
        $randevuId = $request->filled('randevu_id') ? (int) $request->input('randevu_id') : null;
        [$uid, $salonId] = $this->kapsam($request);
        if ($request->boolean('mobil')) {
            return ApiCevap::basarili($this->mobilForm->veri($salonId, $randevuId, (string) $request->input('musteri_ara', ''), $this->telGor($uid, $salonId)));
        }

        return ApiCevap::basarili($this->servis->formVerisi($salonId, $randevuId, $this->telGor($uid, $salonId)));
    }

    public function musteriAra(Request $request)
    {
        [$uid, $salonId] = $this->kapsam($request);

        return ApiCevap::basarili($this->servis->musteriAra($salonId, (string) $request->input('q', ''), $this->telGor($uid, $salonId)));
    }

    /** GET /api/v2/randevu/musteri-paketleri?salon_id=&user_id= — kalan seansli paket/hizmet satislari. */
    public function musteriPaketleri(Request $request)
    {
        [, $salonId] = $this->kapsam($request);

        return ApiCevap::basarili($this->servis->musteriPaketleri($salonId, (int) $request->input('user_id')));
    }

    /** @return array{0:int,1:int} [yetkili id, salon id] */
    private function kapsam(Request $request): array
    {
        $user = $this->kullanici();
        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            throw new YetkisizErisimException('salon_id zorunlu');
        }
        $this->kapi->denetle($user->id, $salonId, IslemYetkileri::RANDEVU_FORM);

        return [(int) $user->id, $salonId];
    }

    private function telGor(int $uid, int $salonId): bool
    {
        return $this->yetki->yetkiliYetkiVar($uid, $salonId, 'musteri.telefon_gor');
    }
}
