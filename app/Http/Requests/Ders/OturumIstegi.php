<?php

namespace App\Http\Requests\Ders;

/** POST /ders/oturum, PATCH /ders/oturum/{id} */
class OturumIstegi extends DersIstegi
{
    public function rules(): array
    {
        return $this->dersKurallari() + [
            'tarih' => ['required', 'date_format:Y-m-d'],
            'not'   => ['nullable', 'string', 'max:1000'],
        ];
    }
}
