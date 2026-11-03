<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Kampanya\KampanyaSorgusu;
use App\Services\Musteri\PortfoyKapsami;
use App\Services\Yetki\GuvenlikKapisi;
use Illuminate\Http\Request;

/**
 * Reklam Yönetimi controller'larının ortak tabanı — yetki anahtarı, kill-switch ve
 * "yol parametresindeki kampanya → salon → kapsam + yetki" adımı TEK yerde.
 *
 * Yetki: eski menü/sayfa anahtarı `pazarlama.kampanya_yonet` (okuma + yazma). Açık telefon
 * `musteri.telefon_gor`. Yazmalar KAMPANYA_V2_YAZMA_AKTIF arkasında (kapalıyken 409) — kapsam/yetki SONRASI.
 */
abstract class KampanyaTabanController extends V2Controller
{
    protected const YETKI = 'pazarlama.kampanya_yonet';

    protected ?string $yazmaAlani = 'kampanya';

    protected string $yazmaKapaliMesaj = 'Reklam yönetimi v2 yazma şu an kapalı';

    /** @return array{0:int,1:int} [yetkili id, salon id] — istekteki salon_id için. */
    protected function salon(Request $request): array
    {
        return $this->salonDenetle($request, self::YETKI);
    }

    /**
     * Yoldaki kampanyanın salonu (pasif / yok → 404) → kapsam + yetki.
     *
     * @return array{0:int,1:int,2:object} [yetkili id, salon id, kampanya satırı]
     */
    protected function kampanya(int $id): array
    {
        $uid = (int) $this->kullanici()->id;
        $sorgu = app(KampanyaSorgusu::class);
        $salonId = $this->bulundu($sorgu->salonId($id), 'Kampanya bulunamadı');
        app(GuvenlikKapisi::class)->denetle($uid, $salonId, self::YETKI);

        return [$uid, $salonId, $sorgu->kayit($id, $salonId)];
    }

    /** @return array{yazabilir:bool, telefon_gor:bool} */
    protected function ctx(int $uid, int $salonId): array
    {
        return [
            'yazabilir'   => (bool) config('kampanya.yazma_aktif'),
            'telefon_gor' => $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor'),
        ];
    }

    /** Personel kapsamı (musteri.tum_portfoy_gor yoksa kendi portföyü) — kitle + katılımcı ekleme. */
    protected function kisitPersonel(int $uid, int $salonId): ?int
    {
        return app(PortfoyKapsami::class)->kisitliPersonel($uid, $salonId);
    }
}
