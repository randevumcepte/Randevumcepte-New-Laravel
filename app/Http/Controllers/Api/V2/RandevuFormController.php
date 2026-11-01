<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Randevu\RandevuFormServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Randevu formu veri uclari (Faz 3b-2) — salt okuma.
 *   GET /api/v2/randevu/form-verisi?salon_id=&randevu_id=   (secenekler + duzenleme on doldurmasi)
 *   GET /api/v2/randevu/musteri-ara?salon_id=&q=            (portfoy aramasi, maskeli telefon)
 *   GET /api/v2/randevu/musteri-paketleri?salon_id=&user_id= (kalan seansli paket/hizmet satislari)
 * Yetki: randevu.olustur VEYA randevu.duzenle_iptal. Telefon: musteri.telefon_gor.
 */
class RandevuFormController extends Controller
{
    public function __construct(
        private RandevuFormServisi $servis,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
    ) {}

    public function formVerisi(Request $request)
    {
        [$uid, $salonId] = $this->kapsam($request);
        $randevuId = $request->filled('randevu_id') ? (int) $request->input('randevu_id') : null;

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
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }
        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            throw new YetkisizErisimException('salon_id zorunlu');
        }
        $this->kapi->denetle($user->id, $salonId, ['randevu.olustur', 'randevu.duzenle_iptal']);

        return [(int) $user->id, $salonId];
    }

    private function telGor(int $uid, int $salonId): bool
    {
        return $this->yetki->yetkiliYetkiVar($uid, $salonId, 'musteri.telefon_gor');
    }
}
