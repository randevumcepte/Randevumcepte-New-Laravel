<?php

namespace App\Http\Requests\Ayar;

use App\Domain\Ayar\HaftalikPlan;
use Illuminate\Validation\Rule;

/**
 * PUT /ayar/calisma-saatleri  body: {salon_id, gunler:[7 x {haftanin_gunu 1-7, calisiyor, baslangic_saati,
 * bitis_saati, mola_var, mola_baslangic_saati, mola_bitis_saati}]}. Saat sirasi HaftalikPlan kurali.
 * Mobil: yalniz=calisma|mola (yalniz o yari yazilir), gece_devri=1 (sira denetlenmez). Web gondermez.
 */
class CalismaSaatiIstegi extends AyarIstegi
{
    use HaftalikPlanKurallari;

    protected array $jsonAlanlar = ['gunler'];

    protected function alanKurallari(): array
    {
        return $this->planKurallari() + [
            'yalniz'     => ['sometimes', 'nullable', Rule::in([HaftalikPlan::CALISMA, HaftalikPlan::MOLA])],
            'gece_devri' => ['sometimes', 'boolean'],
        ];
    }
}
