<?php

namespace App\Http\Requests\Musteri;

/** POST /musteri/{id}/olcumler */
class OlcumIstegi extends MusteriIstegi
{
    public function rules(): array
    {
        $sayi = fn (int $max) => ['nullable', 'numeric', 'min:0', 'max:'.$max];

        return [
            'olcum_tarihi' => ['nullable', 'date_format:Y-m-d'],
            'olcum_saati'  => ['nullable', 'date_format:H:i'],
            'boy'          => $sayi(260),
            'kilo'         => $sayi(400),
            'yas'          => ['nullable', 'integer', 'min:0', 'max:120'],
            'yag_orani'    => $sayi(100),
            'odem'         => $sayi(9999),
            'kas_puani'    => $sayi(9999),
            'kas_kg'       => $sayi(400),
            'ic_yaglanma'  => $sayi(100),
            'not'          => ['nullable', 'string', 'max:1000'],
        ];
    }
}
