<?php

namespace App\Http\Requests\Ayar;

/** POST /ayar/hizmet-kategorileri  body: {salon_id, kategori_adi} */
class HizmetKategoriIstegi extends AyarIstegi
{
    protected function alanKurallari(): array
    {
        return ['kategori_adi' => ['required', 'string', 'min:2', 'max:255']];
    }
}
