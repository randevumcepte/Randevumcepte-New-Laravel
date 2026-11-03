<?php

namespace App\Http\Requests\Stok;

/** POST /stok/alis — mal kabul fisi. Urun secilmemis bos satirlar (eski UI) atilir. */
class AlisGirisiIstegi extends StokIstegi
{
    protected array $jsonAlanlar = ['kalemler'];

    protected function alanKurallari(): array
    {
        return [
            'tedarikci_id'                => ['nullable', 'integer', 'min:1'],
            'depo_id'                     => ['nullable', 'integer', 'min:1'],
            'aciklama'                    => ['nullable', 'string', 'max:500'],
            'kalemler'                    => ['required', 'array', 'min:1', 'max:200'],
            'kalemler.*.urun_id'          => ['required', 'integer', 'min:1'],
            'kalemler.*.miktar'           => ['required', 'numeric', 'gt:0', 'max:999999'],
            'kalemler.*.birim_alis_fiyati'=> ['nullable', 'numeric', 'min:0'],
            'kalemler.*.depo_id'          => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (is_array($k = $this->input('kalemler'))) {
            $this->merge(['kalemler' => array_values(array_filter($k, fn ($s) => is_array($s) && ! empty($s['urun_id'])))]);
        }
    }

    public function messages(): array
    {
        return parent::messages() + ['kalemler.required' => 'En az 1 kalem ekleyin (ürün seçin + miktar girin).'];
    }
}
