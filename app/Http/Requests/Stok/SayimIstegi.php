<?php

namespace App\Http\Requests\Stok;

/** POST /stok/sayim — sayilan miktarlar; depo ust duzeyde (kalem bazinda da verilebilir). */
class SayimIstegi extends StokIstegi
{
    protected array $jsonAlanlar = ['kalemler'];

    protected function alanKurallari(): array
    {
        return [
            'depo_id'                  => ['nullable', 'integer', 'min:1'],
            'kalemler'                 => ['required', 'array', 'min:1', 'max:5000'],
            'kalemler.*.urun_id'       => ['required', 'integer', 'min:1'],
            'kalemler.*.sayilan_miktar'=> ['required', 'numeric', 'min:0', 'max:999999'],
            'kalemler.*.depo_id'       => ['nullable', 'integer', 'min:1'],
        ];
    }
}
