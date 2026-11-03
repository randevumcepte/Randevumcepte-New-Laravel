<?php

namespace App\Http\Requests\Ayar;

/**
 * POST /ayar/hizmetler/havuzdan  body: {salon_id, hizmetler:[{hizmet_id, fiyat?, sure_dk?, personel_ids[]?, cihaz_ids[]?}]}
 * (eski 2 adimli "sistemden hizmet sec → personel/fiyat/sure gir" akisinin tek istegi).
 */
class HavuzHizmetIstegi extends AyarIstegi
{
    protected array $jsonAlanlar = ['hizmetler'];

    protected function alanKurallari(): array
    {
        return [
            'hizmetler'                  => ['required', 'array', 'min:1', 'max:200'],
            'hizmetler.*.hizmet_id'      => ['required', 'integer', 'min:1', 'distinct'],
            'hizmetler.*.fiyat'          => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'hizmetler.*.sure_dk'        => ['nullable', 'integer', 'min:0', 'max:1440'],
            'hizmetler.*.personel_ids'   => ['sometimes', 'array'],
            'hizmetler.*.personel_ids.*' => ['integer', 'min:1'],
            'hizmetler.*.cihaz_ids'      => ['sometimes', 'array'],
            'hizmetler.*.cihaz_ids.*'    => ['integer', 'min:1'],
        ];
    }
}
