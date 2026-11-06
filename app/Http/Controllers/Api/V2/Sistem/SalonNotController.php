<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Domain\SistemYonetim\SalonNotTipi;
use App\Services\SistemYonetim\Salon\SalonNotServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** POST /sistem/salon/{id}/not   DELETE /sistem/salon/not/{notId}   POST /sistem/salon/not/{notId}/pin. Destek yalniz kendi salonlarinda. */
class SalonNotController extends SistemTabanController
{
    public function __construct(private SalonNotServisi $servis) {}

    public function ekle(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, [
            'baslik' => ['nullable', 'string', 'max:200'],
            'icerik' => ['required', 'string', 'min:2', 'max:60000'],
            'tip'    => ['nullable', Rule::enum(SalonNotTipi::class)],
            'pinned' => ['nullable', 'boolean'],
        ]);
        $this->servis->ekle($this->salon($id, ['id', 'salon_adi']), $g, $this->yonetici());

        return ApiCevap::basarili(null, 'Not eklendi.');
    }

    public function sil(int $notId)
    {
        $this->yazmaAcikMi();
        $this->servis->sil($this->kapsamdakiNot($notId), $this->yonetici());

        return ApiCevap::basarili(null, 'Not silindi.');
    }

    public function sabit(int $notId)
    {
        $this->yazmaAcikMi();
        $this->servis->sabitDegistir($this->kapsamdakiNot($notId));

        return ApiCevap::basarili();
    }

    private function kapsamdakiNot(int $notId): object
    {
        $not = $this->servis->bul($notId);
        $this->salon((int) $not->salon_id, ['id']);

        return $not;
    }
}
