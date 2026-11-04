<?php

namespace App\Http\Requests\Ders;

/** POST /ders/tekrarli, PATCH /ders/tekrarli/{id} */
class TekrarliIstegi extends DersIstegi
{
    public function rules(): array
    {
        return [
            'musteri_id'        => ['required', 'integer'],
            'hizmet_id'         => ['required', 'integer'],
            'toplam_seans'      => ['required', 'integer', 'min:1', 'max:1000'],
            'sablonlar'         => ['nullable', 'array'],
            'sablonlar.*'       => ['integer'],
            'gunler'            => ['nullable', 'array'],
            'gunler.*'          => ['integer', 'between:1,7'],
            'saatler'           => ['nullable', 'array'],
            'saatler.*'         => ['date_format:H:i'],
            'personel_id'       => ['nullable', 'integer'],
            'baslangic'         => ['nullable', 'date_format:Y-m-d'],
            'adisyon_paket_id'  => ['nullable', 'integer'],
            'adisyon_hizmet_id' => ['nullable', 'integer'],
        ];
    }
}
