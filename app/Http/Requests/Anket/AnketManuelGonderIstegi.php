<?php

namespace App\Http\Requests\Anket;

use App\Domain\Telefon\TelefonNumarasi;
use App\Http\Requests\V2Istegi;

/** POST /anket/sablonlar/{id}/gonder — test / manuel gönderim (eski web alanı cep_telefon da kabul). */
class AnketManuelGonderIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        return [
            'telefon'     => ['required', 'string', 'max:25'],
            'ad_soyad'    => ['nullable', 'string', 'max:150'],
            'user_id'     => ['nullable', 'integer', 'min:0'],
            'randevu_id'  => ['nullable', 'integer', 'min:0'],
            'personel_id' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return ['telefon' => 'Telefon', 'ad_soyad' => 'Ad Soyad'] + parent::attributes();
    }

    protected function prepareForValidation(): void
    {
        $this->eskiAdlar(['cep_telefon' => 'telefon']);
        parent::prepareForValidation();
    }

    public function after(): array
    {
        return [function ($v) {
            if (strlen(ltrim(TelefonNumarasi::kanonik((string) $this->input('telefon')), '+')) < 10) {
                $v->errors()->add('telefon', 'Telefon 10 haneli olmalı (5XXXXXXXXX).');
            }
        }];
    }
}
