<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Kabuk\IletisimDurumServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Header iletisim rozetleri (web kabugu + Flutter ayni uc). Ayri uclar: yavas SMS
 * saglayicisi WhatsApp rozetini ve menuyu bekletmez.
 *
 * GET /api/v2/kabuk/sms?salon_id=       → {kalan:?int, url:?string} | null (personel rolu)
 * GET /api/v2/kabuk/whatsapp?salon_id=  → {bagli, numara, kontor, kontor_kritik, url} | null
 */
class KabukDurumController extends V2Controller
{
    public function __construct(private IletisimDurumServisi $servis) {}

    public function sms(Request $request)
    {
        [$uid, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->servis->sms($uid, $salonId));
    }

    public function whatsapp(Request $request)
    {
        [$uid, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->servis->whatsapp($uid, $salonId));
    }

    /** Kapsam denetimi serviste (rol kurali ile birlikte); burada yalniz kimlik + salon_id. */
    private function salon(Request $request): array
    {
        return [(int) $this->kullanici()->id, $this->salonId($request)];
    }
}
