<?php

namespace App\Http\Requests\Bildirim;

use App\Domain\Bildirim\CihazPlatformu;
use App\Http\Requests\V2Istegi;
use Illuminate\Validation\Rule;

/** POST /bildirim/cihaz ve /musteri-uygulama/bildirim/cihaz. Eski ad `appBundle` de kabul edilir. */
class CihazKaydetIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        return [
            'token'      => ['required', 'string', 'min:20', 'max:512'],
            'platform'   => ['required', Rule::in(CihazPlatformu::kodlar())],
            'cihaz'      => ['nullable', 'string', 'max:190'],
            'app_bundle' => ['nullable', 'string', 'max:190'],
        ];
    }

    public function attributes(): array
    {
        return ['token' => 'Bildirim kimliği', 'platform' => 'Platform'] + parent::attributes();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->eskiAdlar(['appBundle' => 'app_bundle']);
    }
}
