<?php

namespace App\Http\Requests\Ders;

/** POST /ders/yayinla — hafta tavani ve bitis kurali Domain\Ders\YayinAraligi'nda. */
class YayinlaIstegi extends DersIstegi
{
    public function rules(): array
    {
        return [
            'baslangic' => ['nullable', 'date_format:Y-m-d'],
            'bitis'     => ['nullable', 'date_format:Y-m-d'],
            'hafta'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
