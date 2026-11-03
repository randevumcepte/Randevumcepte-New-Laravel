<?php

namespace App\Http\Requests\OnGorusme;

use App\Http\Requests\V2Istegi;

/**
 * POST /ongorusme/{id}/satis-yapilmadi — sebep notu zorunlu (eski swal ile aynı).
 * Eski istemci adı: satisyapilmamasebebi → not.
 */
class OnGorusmeSatisYapilmadiIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        return ['not' => ['required', 'string', 'max:1000']];
    }

    public function attributes(): array
    {
        return ['not' => 'Satış yapılmama sebebi'] + parent::attributes();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->eskiAdlar(['satisyapilmamasebebi' => 'not']);
        if (is_string($this->input('not'))) {
            $this->merge(['not' => trim($this->input('not'))]);
        }
    }
}
