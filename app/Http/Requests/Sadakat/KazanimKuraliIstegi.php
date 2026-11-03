<?php

namespace App\Http\Requests\Sadakat;

use App\Http\Requests\V2Istegi;
use Illuminate\Validation\Validator;

/** PUT /sadakat/kazanim — açıkken TL tutarı ≥ 1 ve en az bir puan değeri (eski web istemci kontrolü sunucuya taşındı). */
class KazanimKuraliIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        return [
            'aktif'          => ['required', 'boolean'],
            'harcama_tutari' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'harcama_puani'  => ['required', 'integer', 'min:0', 'max:100000'],
            'ziyaret_puani'  => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function attributes(): array
    {
        return ['harcama_tutari' => 'TL tutarı', 'harcama_puani' => 'Ödeme puanı', 'ziyaret_puani' => 'Randevu puanı'] + parent::attributes();
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($this->boolean('aktif') && ! (int) $this->input('harcama_puani') && ! (int) $this->input('ziyaret_puani')) {
                $v->errors()->add('harcama_puani', 'Kural açıkken en az bir puan değeri girin.');
            }
        }];
    }
}
