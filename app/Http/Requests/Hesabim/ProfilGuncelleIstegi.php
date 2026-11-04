<?php

namespace App\Http\Requests\Hesabim;

use App\Http\Requests\V2Istegi;

/** PUT /api/v2/profil — kimlik token'dan; govdedeki yetkili_id (eski v1) yok sayılır. */
class ProfilGuncelleIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        return [
            'name'          => ['required', 'string', 'max:255'],
            'gsm1'          => ['required', 'string', 'max:30'],
            'email'         => ['sometimes', 'nullable', 'email', 'max:255'],
            'unvan'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'cinsiyet'      => ['sometimes', 'nullable', 'integer', 'in:0,1'],
            'sms_gonderimi' => ['sometimes', 'nullable', 'integer', 'in:0,1'],
            'password'      => ['sometimes', 'nullable', 'string', 'min:6', 'max:72'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'email'      => 'Geçerli bir e-posta adresi giriniz.',
            'min.string' => ':attribute en az :min karakter olmalı.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'Ad soyad', 'gsm1' => 'Telefon', 'email' => 'E-posta', 'unvan' => 'Ünvan',
            'cinsiyet' => 'Cinsiyet', 'password' => 'Şifre',
        ] + parent::attributes();
    }
}
