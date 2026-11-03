<?php

namespace App\Http\Requests\Sadakat;

use App\Domain\Cark\PuanOdulTipi;
use App\Http\Requests\V2Istegi;
use Illuminate\Validation\Rule;

/** POST /sadakat/puan-odulu · PATCH /sadakat/puan-odulu/{id} — eski web/mobil alan adlarıyla aynı. */
class PuanOdulIstegi extends V2Istegi
{
    protected array $jsonAlanlar = ['salon_ids'];

    protected function alanKurallari(): array
    {
        return [
            'baslik'      => ['required', 'string', 'max:150'],
            'aciklama'    => ['nullable', 'string', 'max:300'],
            'puan_esigi'  => ['required', 'integer', 'min:1', 'max:10000000'],
            'tip'         => ['required', Rule::in(PuanOdulTipi::kodlar())],
            'deger'       => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'aktif'       => ['sometimes', 'boolean'],
            'sira'        => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'salon_ids'   => ['sometimes', 'nullable', 'array'],
            'salon_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['baslik' => 'Başlık', 'puan_esigi' => 'Gerekli puan', 'tip' => 'Ödül tipi', 'deger' => 'Değer'] + parent::attributes();
    }
}
