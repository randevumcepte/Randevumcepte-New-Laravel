<?php

namespace App\Http\Requests\Musteri;

use App\Domain\Musteri\MusteriSozluk;
use Illuminate\Validation\Rule;

/** PATCH /musteri/{id}/saglik — yalniz gonderilen alanlar yazilir. */
class SaglikIstegi extends MusteriIstegi
{
    public function rules(): array
    {
        $kurallar = [];
        foreach (array_keys(MusteriSozluk::SAGLIK_SORULARI) as $k) {
            $kurallar[$k] = ['sometimes', 'nullable', Rule::in([0, 1, '0', '1', true, false])];
        }
        foreach (MusteriSozluk::SAGLIK_METINLERI as $k) {
            $kurallar[$k] = ['sometimes', 'nullable', 'string', 'max:2000'];
        }
        $kurallar['cilt_tipi'] = ['sometimes', 'nullable', Rule::in(array_keys(MusteriSozluk::CILT_TIPLERI))];

        return $kurallar;
    }
}
