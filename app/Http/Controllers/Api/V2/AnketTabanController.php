<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Yetki\GuvenlikKapisi;

/**
 * Memnuniyet anketi controller'larının ortak tabanı — yetki anahtarı, yazma bayrağı, telefon görünürlüğü
 * ve "kaydın salonundan kapsam denetimi" tek yerde.
 */
abstract class AnketTabanController extends V2Controller
{
    protected const YETKI = 'pazarlama.anket_yonet';

    protected ?string $yazmaAlani = 'anket';

    protected string $yazmaKapaliMesaj = 'Memnuniyet anketi v2 yazma şu an kapalı';

    protected function telGor(int $uid, int $salonId): bool
    {
        return $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor');
    }

    /** @return array{0:int,1:int} [yetkili id, salon id] — kayıt yoksa 404, kapsam dışıysa 403. */
    protected function kayitDenetle(?int $salonId, string $bulunamadi): array
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu($salonId, $bulunamadi);
        app(GuvenlikKapisi::class)->denetle($uid, $salonId, self::YETKI);

        return [$uid, $salonId];
    }
}
