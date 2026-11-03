<?php

namespace App\Http\Requests\Ayar;

/**
 * POST /ayar/cihazlar · PATCH /ayar/cihazlar/{id}  body: {salon_id, cihaz_adi, gunler[7]?}
 * Yeni cihazda plan zorunlu (eski modal 7 gunu hep gonderiyordu); duzenlemede gonderilmezse korunur.
 */
class CihazIstegi extends AyarIstegi
{
    use HaftalikPlanKurallari;

    protected array $jsonAlanlar = ['gunler'];

    protected function alanKurallari(): array
    {
        $yeni = $this->route('id') === null;

        return ['cihaz_adi' => [$yeni ? 'required' : 'sometimes', 'string', 'max:255']]
            + $this->planKurallari($yeni ? 'required' : 'sometimes');
    }

    public function veri(): array
    {
        return $this->validated();
    }
}
