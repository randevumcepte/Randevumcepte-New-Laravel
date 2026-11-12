<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Musteri\MusteriOkumaServisi;
use App\Services\Musteri\PortfoyKapsami;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use Illuminate\Http\Request;

/**
 * Musteri v2 controller'larinin ortak tabani — salon + KAPSAM + YETKI + portfoy
 * denetimi ve yazma kill-switch'i (config/musteri.php) TEK yerde.
 *
 * Musteri kaydi birden cok salona ait olabildigi icin salon HER ZAMAN istekten
 * (salon_id) gelir; musterinin o salonun aktif portfoyunde ve kullanicinin portfoy
 * kapsaminda (musteri.tum_portfoy_gor) olmasi zorunludur — degilse 404.
 */
abstract class MusteriTabanController extends V2Controller
{
    /** Detay sayfasina erisim (eski musteridetay: detay VEYA not VEYA gecmis satis). */
    public const DETAY = ['musteri.detay_gor', 'musteri.not_yaz', 'musteri.gecmis_satis_gor'];

    protected ?string $yazmaAlani = 'musteri';

    protected string $yazmaKapaliMesaj = 'Müşteri v2 yazma şu an kapalı';

    /**
     * Yol parametresindeki musteri icin kapsam + yetki + portfoy.
     *
     * @return array{0:int,1:int,2:?int} [yetkili id, salon id, kisit personel id]
     */
    protected function musteriDenetle(Request $r, int $musteriId, array|string $yetki): array
    {
        [$uid, $salonId] = $this->salonDenetle($r, $yetki);

        return [$uid, $salonId, app(MusteriOkumaServisi::class)->kapsamdaZorunlu($uid, $salonId, $musteriId)];
    }

    /** Personel (rol 5) hesaplarina kapali islemler (eski panelde butonlar gizliydi). */
    protected function yoneticiDenetle(int $uid, int $salonId): void
    {
        app(GuvenlikKapisi::class)->yoneticiDenetle($uid, $salonId);
    }

    protected function yetkiVar(int $uid, int $salonId, string $anahtar): bool
    {
        return app(YetkiServisi::class)->yetkiliYetkiVar($uid, $salonId, $anahtar);
    }

    protected function portfoy(): PortfoyKapsami
    {
        return app(PortfoyKapsami::class);
    }
}
