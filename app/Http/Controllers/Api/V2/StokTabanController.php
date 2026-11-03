<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Services\Stok\StokKapsam;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use Illuminate\Http\Request;

/**
 * Stok v2 controller'larinin ortak tabani — salon cozumu + KAPSAM + YETKI + yazma
 * kill-switch'i (config/stok.php) TEK yerde.
 *
 * Yetki haritasi (eski StoreAdminController::stokApi + sayfa kapisiyla BIREBIR):
 *   ekran / okuma   : urun.tanim_olustur | urun.stok_giris | urun.stok_sayim | urun.tedarikci_yonet
 *   urun listesi    : yukaridakiler | urun.sat (satis yapan urunleri gorebilmeli)
 *   urun/kategori/depo/recete yaz : urun.tanim_olustur
 *   tedarikci yaz   : urun.tedarikci_yonet
 *   manuel/alis/transfer : urun.stok_giris      sayim : urun.stok_sayim
 *   hizli satis     : urun.sat (+ pesinse satis.tahsilat_al)
 */
abstract class StokTabanController extends V2Controller
{
    public const GORUNTULE = ['urun.tanim_olustur', 'urun.stok_giris', 'urun.stok_sayim', 'urun.tedarikci_yonet'];

    public const URUN_OKU = [...self::GORUNTULE, 'urun.sat'];

    protected ?string $yazmaAlani = 'stok';

    protected string $yazmaKapaliMesaj = 'Stok v2 yazma şu an kapalı';

    /**
     * salon_id (query/govde; eski 'sube' de kabul) → kapsam + yetki (OR).
     *
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function salonYetki(Request $r, array|string $yetki): array
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = (int) ($r->input('salon_id') ?: $r->input('sube'));
        if (! $salonId) {
            throw new GecersizIslemException('salon_id zorunlu');
        }
        $this->kapi()->denetle($uid, $salonId, $yetki);

        return [$uid, $salonId];
    }

    /**
     * Yol parametresindeki kaydin salonu cozulur (yoksa 404) → kapsam + yetki.
     *
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function kayitYetki(string $kaynak, int $id, array|string $yetki): array
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = app(StokKapsam::class)->salonId($kaynak, $id);
        $this->kapi()->denetle($uid, $salonId, $yetki);

        return [$uid, $salonId];
    }

    /** Yazma: kapsam/yetki SONRASI kill-switch (yetkisiz kullaniciya flag durumu sizmasin). */
    protected function yazilabilir(): void
    {
        $this->yazmaAcikMi();
    }

    protected function yetkiVar(int $uid, int $salonId, string $anahtar): bool
    {
        return app(YetkiServisi::class)->yetkiliYetkiVar($uid, $salonId, $anahtar);
    }

    protected function kapi(): GuvenlikKapisi
    {
        return app(GuvenlikKapisi::class);
    }
}
