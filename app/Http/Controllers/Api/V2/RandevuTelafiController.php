<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Randevu\TelafiRandevuServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * TELAFI RANDEVUSU (A tipi) uclari — ince controller. Yetki: randevu.olustur
 * (eski telafiRandevuOlusturApi ile ayni anahtar).
 *
 * Olustur 'dry_run' alir (VARSAYILAN true → prim devri dahil plan). Gercek yazma
 * config('randevu.yazma_aktif') arkasinda; kapaliyken 409.
 */
class RandevuTelafiController extends Controller
{
    public function __construct(
        private TelafiRandevuServisi $servis,
        private GuvenlikKapisi $kapi,
    ) {}

    /** GET /api/v2/randevu/telafi-secenekler?salon_id= — personel/oda/cihaz listeleri. */
    public function secenekler(Request $request)
    {
        $user = $this->kimlik();
        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }
        $this->kapi->denetle($user->id, $salonId, ['randevu.olustur']);

        return ApiCevap::basarili($this->servis->secenekler($salonId));
    }

    /**
     * POST /api/v2/randevu/kalem/{rh}/telafi-randevusu
     * Govde: tarih (Y-m-d), saat (H:i), personel_id?, oda_id?, cihaz_id?, sure_dk?, not?, dry_run?
     */
    public function olustur(Request $request, int $rh)
    {
        $user = $this->kimlik();
        $salonId = $this->servis->salonIdGetir($rh);
        if (! $salonId) {
            return ApiCevap::hata('Randevu kaydı bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, ['randevu.olustur']);

        $yaz = ! $request->boolean('dry_run', true);
        if ($yaz && ! config('randevu.yazma_aktif')) {
            return ApiCevap::hata('Randevu v2 yazma şu an kapalı (yalnızca dry_run)', 409);
        }

        $sonuc = $this->servis->olustur($rh, $request->only(['tarih', 'saat', 'personel_id', 'oda_id', 'cihaz_id', 'sure_dk', 'not']), (int) $user->id, $yaz);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj'] ?? null);
    }

    private function kimlik()
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        return $user;
    }
}
