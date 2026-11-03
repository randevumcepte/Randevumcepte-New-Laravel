<?php

namespace App\Http\Requests\Stok;

/** POST /stok/transfer — depolar arasi. */
class TransferIstegi extends StokIstegi
{
    protected function alanKurallari(): array
    {
        return [
            'urun_id'        => ['required', 'integer', 'min:1'],
            'kaynak_depo_id' => ['required', 'integer', 'min:1'],
            'hedef_depo_id'  => ['required', 'integer', 'min:1', 'different:kaynak_depo_id'],
            'miktar'         => ['required', 'numeric', 'gt:0', 'max:999999'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + ['hedef_depo_id.different' => 'Kaynak ve hedef depo aynı olamaz.'];
    }
}
