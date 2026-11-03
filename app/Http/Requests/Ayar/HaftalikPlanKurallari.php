<?php

namespace App\Http\Requests\Ayar;

/**
 * 7 gunluk calisma + mola plani dogrulama kurallari — salon calisma saatleri ve cihaz
 * isteklerinin ORTAK parcasi. Saat sirasi kurali HaftalikPlanServisi'nde.
 */
trait HaftalikPlanKurallari
{
    /** @param  'required'|'sometimes'  $zorunluluk */
    protected function planKurallari(string $zorunluluk = 'required'): array
    {
        $saat = ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'];

        return [
            'gunler'                        => [$zorunluluk, 'array', 'size:7'],
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
