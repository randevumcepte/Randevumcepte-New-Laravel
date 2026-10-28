<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Tahsilat\AdisyonOkumaServisi;
use App\Services\Tahsilat\TahsilatYazServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Tahsilat YAZMA API v2 — şimdilik yalnızca DRY-RUN.
 * KVKK: kimlik + kapsam (adisyon bu işletmeye mi ait) + satis.tahsilat_al.
 * Gerçek yazma KAPALI (dry_run=1 zorunlu); kullanıcı onayıyla açılacak.
 */
class TahsilatController extends Controller
{
    public function __construct(
        private TahsilatYazServisi $servis,
        private AdisyonOkumaServisi $adisyonServisi,
        private GuvenlikKapisi $kapi,
    ) {}

    /** POST /api/v2/tahsilat */
    public function ekle(Request $request)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        $adisyonId = (int) $request->input('adisyon_id');
        if (! $adisyonId) {
            return ApiCevap::hata('adisyon_id zorunlu', 422);
        }

        // Tenant izolasyonu: adisyon GERÇEKTEN bu salona mı ait?
        $adisyonSalon = $this->adisyonServisi->salonIdGetir($adisyonId);
        if (! $adisyonSalon) {
            return ApiCevap::hata('Adisyon bulunamadı', 404);
        }
        if ($adisyonSalon !== $salonId) {
            throw new YetkisizErisimException('Adisyon bu işletmeye ait değil');
        }

        // Kapsam + yetki.
        $this->kapi->denetle($user->id, $salonId, ['satis.tahsilat_al']);

        // GÜVENLİK: gerçek finansal yazma HENÜZ ETKİN DEĞİL. dry_run zorunlu.
        if (! $request->boolean('dry_run')) {
            return ApiCevap::hata('Gerçek yazma henüz etkin değil (dry_run=1 zorunlu).', 409);
        }

        $plan = $this->servis->planla(array_merge($request->all(), [
            'salon_id'   => $salonId,
            'adisyon_id' => $adisyonId,
        ]));

        return ApiCevap::basarili($plan, 'DRY-RUN: hiçbir şey yazılmadı.');
    }
}
