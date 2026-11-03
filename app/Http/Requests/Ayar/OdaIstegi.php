<?php

namespace App\Http\Requests\Ayar;

/**
 * POST /ayar/odalar · PATCH /ayar/odalar/{id}  body: {salon_id, oda_adi, personel_ids[], hizmet_ids[]}
 * Eski adlar (oda_personeli[], oda_hizmetleri[] — FormData'da JSON metni de olabilir) kabul edilir.
 * Kimliklerin bu salona ait olmasi serviste suzulur.
 */
class OdaIstegi extends AyarIstegi
{
    protected array $jsonAlanlar = ['personel_ids', 'hizmet_ids'];

    protected function prepareForValidation(): void
    {
        $this->eskiAdlar(['oda_personeli' => 'personel_ids', 'oda_hizmetleri' => 'hizmet_ids']);
        parent::prepareForValidation();
    }

    protected function alanKurallari(): array
    {
        $yeni = $this->route('id') === null;

        return [
            'oda_adi'        => [$yeni ? 'required' : 'sometimes', 'string', 'max:255'],
            'personel_ids'   => ['sometimes', 'array', 'max:200'],
            'personel_ids.*' => ['integer', 'min:1'],
            'hizmet_ids'     => ['sometimes', 'array', 'max:500'],
            'hizmet_ids.*'   => ['integer', 'min:1'],
        ];
    }

    public function veri(): array
    {
        return $this->validated();
    }
}
