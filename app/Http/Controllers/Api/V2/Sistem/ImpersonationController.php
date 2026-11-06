<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\ImpersonationServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 *   POST /sistem/salon/{id}/hesabina-gir {yetkili_id?, sebep?, ticket_id?} → {token, log_id, salon_adi, yetkili, hedef}
 *   POST /sistem/impersonation/{logId}/bitir
 */
class ImpersonationController extends SistemTabanController
{
    public function __construct(private ImpersonationServisi $servis) {}

    public function baslat(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, [
            'yetkili_id' => ['nullable', 'integer', 'min:1'],
            'sebep'      => ['nullable', 'string', 'max:255'],
            'ticket_id'  => ['nullable', 'integer', 'min:1'],
        ]);

        return ApiCevap::basarili($this->servis->baslat($id, $g, $this->yonetici(), (string) $request->ip(), (string) $request->header('User-Agent')));
    }

    public function bitir(int $logId)
    {
        $this->servis->bitir($logId, $this->yonetici());

        return ApiCevap::basarili(null, 'Salon hesabından çıkıldı.');
    }
}
