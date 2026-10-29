<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Panel\PanelKabukServisi;
use App\Services\Yetki\KapsamServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Panel kabuğu API v2 — sol menü + header için gereken minimum veri.
 *
 * GET /api/v2/kabuk?salon_id=  (auth:isletmeyonetim-api)
 *   salon_id verilmezse kullanıcının erişebildiği İLK salon kullanılır. Kapsam
 *   denetimi: salon kullanıcının erişebildiği salonlardan biri olmalı (yoksa 403).
 *   Yetki: menü ZATEN kullanıcının yetkilerine göre filtrelenir; ayrı anahtar
 *   gerekmez (kabuk herkese açık ama yalnızca kendi kapsamındaki salon için).
 */
class KabukController extends Controller
{
    public function __construct(
        private PanelKabukServisi $servis,
        private KapsamServisi $kapsam,
    ) {}

    public function goster(Request $request)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        $salonlar = $this->kapsam->kullaniciSalonlari($user->id);
        if (! $salonlar) {
            throw new YetkisizErisimException('Erişebileceğiniz bir işletme bulunamadı');
        }

        $salonId = (int) $request->query('salon_id', 0);
        if ($salonId <= 0) {
            $salonId = (int) $salonlar[0];
        } elseif (! in_array($salonId, $salonlar, true)) {
            // Kapsam dışı salon → sızıntıyı önle.
            throw new YetkisizErisimException('Bu işletmeye erişim yetkiniz yok');
        }

        return ApiCevap::basarili($this->servis->kabuk((int) $user->id, $salonId));
    }
}
