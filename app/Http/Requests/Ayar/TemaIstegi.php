<?php

namespace App\Http\Requests\Ayar;

use App\Domain\Ayar\SalonTemaSeed;
use Illuminate\Validation\Rule;

/** PUT /ayar/tema  body: {salon_id, preset, customSeedArgb?, brightnessMode} (Flutter AppThemeConfig.toJson) */
class TemaIstegi extends AyarIstegi
{
    protected function alanKurallari(): array
    {
        return [
            'preset'         => ['required', 'string', Rule::in([...array_keys(SalonTemaSeed::PRESET_SEED), SalonTemaSeed::OZEL])],
            'customSeedArgb' => ['nullable', 'required_if:preset,'.SalonTemaSeed::OZEL, 'integer'],
            'brightnessMode' => ['sometimes', Rule::in(SalonTemaSeed::PARLAKLIK_MODLARI)],
        ];
    }

    public function veri(): array
    {
        return $this->only(['preset', 'customSeedArgb', 'brightnessMode']);
    }
}
