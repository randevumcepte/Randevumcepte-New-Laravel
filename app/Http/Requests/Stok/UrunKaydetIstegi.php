<?php

namespace App\Http\Requests\Stok;

use App\Domain\Stok\Birim;
use App\Domain\Stok\UrunTipi;
use Illuminate\Validation\Rule;

/** POST /stok/urunler · PATCH /stok/urunler/{id} */
class UrunKaydetIstegi extends StokIstegi
{
    protected bool $guncellemedeKismi = true;

    protected function alanKurallari(): array
    {
        return [
            'urun_adi'           => ['required', 'string', 'max:255'],
            'fiyat'              => ['required', 'numeric', 'min:0', 'max:99999999'],
            'alis_fiyati'        => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'kdv_orani'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'birim'              => ['sometimes', Rule::enum(Birim::class)],
            'tip'                => ['sometimes', Rule::enum(UrunTipi::class)],
            'kategori_id'        => ['nullable', 'integer', 'min:1'],
            'tedarikci_id'       => ['nullable', 'integer', 'min:1'],
            'barkod'             => ['nullable', 'string', 'max:100'],
            'sku'                => ['nullable', 'string', 'max:50'],
            'stok_adedi'         => ['nullable', 'numeric', 'min:-999999', 'max:999999'],
            'dusuk_stok_siniri'  => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'kritik_stok_siniri' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'resim_url'          => ['nullable', 'string', 'max:500'],
            'aciklama'           => ['nullable', 'string', 'max:2000'],
        ];
    }
}
