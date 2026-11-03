<?php

namespace App\Http\Requests\Ayar;

/**
 * PUT /ayar/calisma-saatleri  body: {salon_id, gunler:[7 x {haftanin_gunu 1-7, calisiyor, baslangic_saati,
 * bitis_saati, mola_var, mola_baslangic_saati, mola_bitis_saati}]}. Saat sirasi servis kurali.
 */
class CalismaSaatiIstegi extends AyarIstegi
{
    protected array $jsonAlanlar = ['gunler'];

    protected function alanKurallari(): array
    {
        $saat = ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'];

        return [
            'gunler'                        => ['required', 'array', 'size:7'],
            'gunler.*.haftanin_gunu'        => ['required', 'integer', 'between:1,7', 'distinct'],
            'gunler.*.calisiyor'            => ['required', 'boolean'],
            'gunler.*.baslangic_saati'      => $saat,
            'gunler.*.bitis_saati'          => $saat,
            'gunler.*.mola_var'             => ['sometimes', 'boolean'],
            'gunler.*.mola_baslangic_saati' => $saat,
            'gunler.*.mola_bitis_saati'     => $saat,
        ];
    }
}
