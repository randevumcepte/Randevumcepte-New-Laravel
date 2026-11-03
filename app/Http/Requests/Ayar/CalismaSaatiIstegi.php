<?php

namespace App\Http\Requests\Ayar;

/**
 * PUT /ayar/calisma-saatleri  body: {salon_id, gunler:[7 x {haftanin_gunu 1-7, calisiyor, baslangic_saati,
 * bitis_saati, mola_var, mola_baslangic_saati, mola_bitis_saati}]}. Saat sirasi servis kurali.
 */
class CalismaSaatiIstegi extends AyarIstegi
{
    use HaftalikPlanKurallari;

    protected array $jsonAlanlar = ['gunler'];

    protected function alanKurallari(): array
    {
        return $this->planKurallari();
    }
}
