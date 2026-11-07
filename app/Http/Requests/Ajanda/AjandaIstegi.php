<?php

namespace App\Http\Requests\Ajanda;

use App\Http\Requests\V2Istegi;

/** POST /ajanda, PATCH /ajanda/{id} */
class AjandaIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        return [
            'baslik'           => ['required', 'string', 'max:255'],
            'icerik'           => ['nullable', 'string'],
            'tarih'            => ['required', 'date'],
            'saat'             => ['required', 'date_format:H:i'],
            'hatirlatma'       => ['nullable', 'boolean'],
            'hatirlatma_saati' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function attributes(): array
    {
        return ['baslik' => 'Başlık', 'icerik' => 'İçerik', 'tarih' => 'Tarih', 'saat' => 'Saat', 'hatirlatma' => 'Hatırlatma',
            'hatirlatma_saati' => 'Hatırlatma saati'] + parent::attributes();
    }
}
