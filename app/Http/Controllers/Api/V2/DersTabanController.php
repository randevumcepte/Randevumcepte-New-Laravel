<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Models\Salonlar;
use App\Services\Musteri\PortfoyKapsami;
use App\Services\Yetki\GuvenlikKapisi;
use Illuminate\Http\Request;

/**
 * Grup dersi controller'larinin ortak tabani: kimlik + kapsam + yetki + modul kapisi + yazma
 * kill-switch'i (config/ders.php) TEK yerde. Yetki anahtarlari eski panelle ayni:
 *   OKU = randevu.takvim_gor · YAZ = randevu.olustur · SIL = randevu.duzenle_iptal
 */
abstract class DersTabanController extends V2Controller
{
    protected const OKU = 'randevu.takvim_gor';

    protected const YAZ = 'randevu.olustur';

    protected const SIL = 'randevu.duzenle_iptal';

    protected ?string $yazmaAlani = 'ders';

    protected string $yazmaKapaliMesaj = 'Grup dersi v2 yazma şu an kapalı';

    /** @return array{0:int,1:int} [yetkili id, salon id] */
    protected function dersSalonu(Request $request, string $yetki): array
    {
        [$uid, $salonId] = $this->salonDenetle($request, $yetki);
        $this->modulAcik($salonId);

        return [$uid, $salonId];
    }

    /**
     * Yol parametresindeki kaydin salonu (yoksa 404) → kapsam + yetki + modul.
     *
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function kayitSalonu(?int $salonId, string $yetki, string $bulunamadi): array
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu($salonId, $bulunamadi);
        app(GuvenlikKapisi::class)->denetle($uid, $salonId, $yetki);
        $this->modulAcik($salonId);

        return [$uid, $salonId];
    }

    protected function personelId(int $uid, int $salonId): ?int
    {
        return app(PortfoyKapsami::class)->personelId($uid, $salonId);
    }

    private function modulAcik(int $salonId): void
    {
        if (! Salonlar::grupDersiAktif($salonId)) {
            throw new YetkisizErisimException('Grup dersi modülü bu işletmede kapalı');
        }
    }
}
