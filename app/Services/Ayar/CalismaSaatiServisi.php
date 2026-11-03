<?php

namespace App\Services\Ayar;

use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Isletme calisma + mola saatleri — eski StoreAdminController@calismasaatleriduzenle,
 * v1 salonsaatleri / salonmolasaatleri / calisma_saati_guncelle_ekle / mola_saati_guncelle_ekle
 * uclarinin TEK karsiligi. Plan bicimi + dogrulama HaftalikPlanServisi'nde (cihaz ile ortak).
 */
class CalismaSaatiServisi
{
    public function __construct(private HaftalikPlanServisi $plan) {}

    public function getir(int $salonId): array
    {
        return ['gunler' => $this->plan->oku('salon', $salonId)];
    }

    /** @param  list<array>  $gunler  dogrulanmis 7 gun */
    public function kaydet(int $salonId, int $yapanId, array $gunler): array
    {
        $calisilan = DB::transaction(fn () => $this->plan->yaz('salon', $salonId, $gunler));

        AktiviteLog::yaz($salonId, $yapanId, 'calisma_saatleri_guncelle', 'salon', $salonId,
            'Çalışma saatleri', 'İşletme çalışma / mola saatleri güncellendi', ['calisilan_gun' => $calisilan]);

        return $this->getir($salonId);
    }
}
