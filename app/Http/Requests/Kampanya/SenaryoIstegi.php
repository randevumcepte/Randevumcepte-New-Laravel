<?php

namespace App\Http\Requests\Kampanya;

use App\Http\Requests\V2Istegi;

/**
 * POST /kampanya/senaryo · PATCH /kampanya/senaryo/{id} · POST /kampanya/senaryo/onizle.
 * Eski istemci adimlar/aksiyonlar'ı JSON METİN gönderiyordu → diziye çevrilir.
 * Önizlemede ad zorunlu değil (route adına göre).
 */
class SenaryoIstegi extends V2Istegi
{
    protected array $jsonAlanlar = ['adimlar', 'aksiyonlar'];

    protected function alanKurallari(): array
    {
        $onizleme = str_ends_with((string) $this->path(), '/onizle');

        return [
            'ad'           => [$onizleme ? 'nullable' : 'required', 'string', 'max:150'],
            'senaryo_tipi' => ['nullable', 'string', 'max:30'],
            'gorev_turu'   => ['nullable', 'integer', 'in:1,2,3,4'],
            'adimlar'      => ['nullable', 'array'],
            'adimlar.*'    => ['nullable', 'string', 'max:1000'],
            'aksiyonlar'   => ['nullable', 'array'],
            'indirim'      => ['nullable', 'string', 'max:30'],
            'gun'          => ['nullable', 'integer', 'min:0', 'max:3650'],
            'hizmet'       => ['nullable', 'string', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return ['ad' => 'Senaryo adı'] + parent::attributes();
    }
}
