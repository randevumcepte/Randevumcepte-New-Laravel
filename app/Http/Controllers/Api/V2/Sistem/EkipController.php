<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Domain\SistemYonetim\SistemRolu;
use App\Services\SistemYonetim\Izleme\EkipServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 *   GET  /sistem/ekip/secenekler  → {roller[], rol_izinleri[], yazabilir, ben_id}
 *   GET  /sistem/ekip             + ListeIstegi
 *   POST /sistem/ekip             yalniz super_admin
 *   PUT  /sistem/ekip/{id}        yalniz super_admin
 *   POST /sistem/ekip/{id}/pasif  yalniz super_admin
 */
class EkipController extends SistemTabanController
{
    public function __construct(private EkipServisi $servis) {}

    public function secenekler()
    {
        return ApiCevap::basarili($this->servis->secenekler($this->yonetici()));
    }

    public function liste(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 25, array_keys(EkipServisi::SIRALAMA), 'durum', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->listele($liste));
    }

    public function ekle(Request $request)
    {
        $this->yazmaAcikMi();
        $this->servis->ekle($this->alanlariDogrula($request, $this->kurallar(null) + ['password' => ['required', 'string', 'min:6']]));

        return ApiCevap::basarili(null, 'Ekip üyesi eklendi.', 201);
    }

    public function guncelle(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, $this->kurallar($id) + ['password' => ['nullable', 'string', 'min:6'], 'aktif' => ['nullable', 'boolean']]);
        $this->servis->guncelle($id, $g, $this->yonetici(), $this->aktifTokenId());

        return ApiCevap::basarili(null, 'Ekip üyesi güncellendi.');
    }

    public function pasif(int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->pasifEt($id, $this->yonetici());

        return ApiCevap::basarili(null, 'Pasif edildi.');
    }

    private function kurallar(?int $id): array
    {
        return [
            'name'    => ['required', 'string', 'min:2', 'max:191'],
            'email'   => ['required', 'email', 'max:191', Rule::unique('sistemyoneticileri', 'email')->ignore($id)],
            'rol'     => ['required', Rule::enum(SistemRolu::class)],
            'telefon' => ['nullable', 'string', 'max:30'],
        ];
    }
}
