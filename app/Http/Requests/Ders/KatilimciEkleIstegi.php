<?php

namespace App\Http\Requests\Ders;

/** POST /ders/oturum/{id}/katilimci */
class KatilimciEkleIstegi extends DersIstegi
{
    public function rules(): array
    {
        return ['musteri_id' => ['required', 'integer']];
    }
}
