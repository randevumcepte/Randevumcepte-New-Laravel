<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Rapor\RaporDonemi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Rapor + kasa controller'larinin ortak tabani — donem ve liste istegi ayristirma,
 * yonetici (rol 5 degil) kapisi TEK yerde. Kimlik/kapsam/yetki V2Controller::salonDenetle.
 */
abstract class RaporTabanController extends V2Controller
{
    /**
     * salon_id → kapsam + yetki (OR). yonetici=true: rol 5 (personel) hesaplari reddedilir
     * (eski panelde personelmi() ile kapatilan ekranlar).
     *
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function salonYetki(Request $r, array|string $yetki, bool $yonetici = false): array
    {
        [$uid, $salonId] = $this->salonDenetle($r, $yetki);
        if ($yonetici) {
            app(GuvenlikKapisi::class)->yoneticiDenetle($uid, $salonId);
        }

        return [$uid, $salonId];
    }

    /** ?donem=&baslangic=&bitis= (bkz. RaporDonemi). */
    protected function donem(Request $r, string $varsayilan = 'ay'): RaporDonemi
    {
        return RaporDonemi::coz($r->input('donem'), $r->input('baslangic'), $r->input('bitis'), $varsayilan);
    }

    /** ListeIstegi::dizi() — sayfalama / arama / siralama. */
    protected function liste(Request $r, array $siralanabilir, ?string $varsayilanSirala, int $boy = 25): array
    {
        return ListeIstegi::istekten($r, $boy, $siralanabilir, $varsayilanSirala)->dizi();
    }
}
