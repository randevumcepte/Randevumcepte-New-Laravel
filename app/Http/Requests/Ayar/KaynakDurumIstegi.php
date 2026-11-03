<?php

namespace App\Http\Requests\Ayar;

/** PATCH /ayar/{cihazlar|odalar}/{id}/durum  body: {salon_id, musait:bool, aciklama?} (eski adlar: durum 0/1) */
class KaynakDurumIstegi extends AyarIstegi
{
    protected function prepareForValidation(): void
    {
        $this->eskiAdlar(['durum' => 'musait']);
    }

    protected function alanKurallari(): array
    {
        return [
            'musait'   => ['required', 'boolean'],
            'aciklama' => ['nullable', 'string', 'max:500'],
        ];
    }
}
