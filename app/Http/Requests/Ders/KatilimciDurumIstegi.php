<?php

namespace App\Http\Requests\Ders;

use App\Domain\Ders\KatilimciDurumu;
use Illuminate\Validation\Rule;

/** PATCH /ders/katilimci/{id}/durum */
class KatilimciDurumIstegi extends DersIstegi
{
    public function rules(): array
    {
        return ['yeni_durum' => ['required', Rule::in(array_column(KatilimciDurumu::isaretlenebilir(), 'value'))]];
    }
}
