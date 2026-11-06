<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Salon\SalonHesapServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/** POST /sistem/salon/{id}/hesap-pasif {yetkili_id, aktif}   POST /sistem/salon/{id}/sahip-yap {yetkili_id} */
class SalonHesapController extends SistemTabanController
{
    private const YETKILI = ['required', 'integer', 'min:1'];

    public function __construct(private SalonHesapServisi $servis) {}

    public function aktiflik(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, ['yetkili_id' => self::YETKILI, 'aktif' => ['required', 'boolean']]);

        return ApiCevap::basarili(null, $this->servis->aktiflikDegistir($this->salon($id, ['id', 'salon_adi']), (int) $g['yetkili_id'], (bool) $g['aktif']));
    }

    public function sahipYap(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, ['yetkili_id' => self::YETKILI]);

        return ApiCevap::basarili(null, $this->servis->sahipYap($this->salon($id, ['id', 'salon_adi']), (int) $g['yetkili_id']));
    }
}
