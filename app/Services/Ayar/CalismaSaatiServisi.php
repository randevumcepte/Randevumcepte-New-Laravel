<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\HaftalikPlan;
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

    /**
     * @param  list<array>  $gunler  dogrulanmis 7 gun
     * @param  ?string  $yalniz  mobil ekranlar yalniz kendi yarisini kaydeder (HaftalikPlan)
     * @param  bool  $geceDevri  mobil ekranin kaydettigi 10:00-00:00 gibi saatler (HaftalikPlan)
     */
    public function kaydet(int $salonId, int $yapanId, array $gunler, ?string $yalniz = null, bool $geceDevri = false): array
    {
        $acikGun = DB::transaction(fn () => $this->plan->yaz('salon', $salonId, $gunler, $yalniz, $geceDevri));

        AktiviteLog::yaz($salonId, $yapanId, 'calisma_saatleri_guncelle', 'salon', $salonId,
            'Çalışma saatleri', 'İşletme çalışma / mola saatleri güncellendi',
            $yalniz === HaftalikPlan::MOLA ? ['mola_olan_gun' => $acikGun] : ['calisilan_gun' => $acikGun]);

        return $this->getir($salonId);
    }
}
