<?php

namespace App\Http\Requests\Kasa;

use App\Http\Requests\V2Istegi;

/** POST /kasa/para-giris ve /kasa/para-cikis (cikista onay_kodu zorunlu). */
class ParaHareketIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        $kurallar = [
            'tarih'            => ['required', 'date_format:Y-m-d'],
            'tutar'            => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'odeme_yontemi_id' => ['required', 'integer', 'exists:odeme_yontemleri,id'],
            'personel_id'      => ['nullable', 'integer', 'min:1'],
            'aciklama'         => ['nullable', 'string', 'max:500'],
        ];
        if (str_ends_with($this->path(), 'para-cikis')) {
            $kurallar['onay_kodu'] = ['required', 'string', 'max:10'];
        }

        return $kurallar;
    }
}
