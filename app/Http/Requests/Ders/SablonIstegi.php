<?php

namespace App\Http\Requests\Ders;

/** POST /ders/sablon, PATCH /ders/sablon/{id} */
class SablonIstegi extends DersIstegi
{
    public function rules(): array
    {
        return $this->dersKurallari() + ['hafta_gunu' => ['required', 'integer', 'between:1,7']];
    }
}
