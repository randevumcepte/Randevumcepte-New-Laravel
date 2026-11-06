<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Izleme\SistemProfilServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 *   GET  /sistem/profil       → {profil, son_girisler[], son_aktiviteler[]}
 *   PUT  /sistem/profil       {name, email, telefon?}
 *   POST /sistem/profil/sifre {mevcut_sifre, yeni_sifre, yeni_sifre_confirmation}
 */
class SistemProfilController extends SistemTabanController
{
    public function __construct(private SistemProfilServisi $servis) {}

    public function ozet()
    {
        return ApiCevap::basarili($this->servis->ozet($this->yonetici()));
    }

    public function guncelle(Request $request)
    {
        $this->yazmaAcikMi();
        $yonetici = $this->yonetici();
        $g = $this->alanlariDogrula($request, [
            'name'    => ['required', 'string', 'min:2', 'max:191'],
            'email'   => ['required', 'email', 'max:191', Rule::unique('sistemyoneticileri', 'email')->ignore($yonetici->id)],
            'telefon' => ['nullable', 'string', 'max:30'],
        ]);

        return ApiCevap::basarili($this->servis->guncelle($yonetici, $g), 'Profil güncellendi.');
    }

    public function sifre(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, [
            'mevcut_sifre' => ['required', 'string'],
            'yeni_sifre'   => ['required', 'string', 'min:6', 'confirmed'],
        ]);
        $this->servis->sifreDegistir($this->yonetici(), $g['mevcut_sifre'], $g['yeni_sifre'], $this->aktifTokenId());

        return ApiCevap::basarili(null, 'Şifre güncellendi.');
    }
}
