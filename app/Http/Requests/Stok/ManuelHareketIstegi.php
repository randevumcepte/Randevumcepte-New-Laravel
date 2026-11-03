<?php

namespace App\Http\Requests\Stok;

use App\Domain\Stok\HareketTipi;
use Illuminate\Validation\Rule;

/** POST /stok/hareketler — fire / iade / sarf / manuel duzeltme. */
class ManuelHareketIstegi extends StokIstegi
{
    protected function alanKurallari(): array
    {
        return [
            'urun_id'            => ['required', 'integer', 'min:1'],
            'depo_id'            => ['nullable', 'integer', 'min:1'],
            'hareket_tipi'       => ['required', Rule::in(HareketTipi::manuelDegerler())],
            'miktar'             => ['required', 'numeric', 'not_in:0', 'min:-999999', 'max:999999'],
            'birim_alis_fiyati'  => ['nullable', 'numeric', 'min:0'],
            'birim_satis_fiyati' => ['nullable', 'numeric', 'min:0'],
            'aciklama'           => ['nullable', 'string', 'max:500'],
        ];
    }
}
