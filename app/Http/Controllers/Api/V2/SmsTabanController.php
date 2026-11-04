<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Services\Musteri\PortfoyKapsami;
use App\Services\Yetki\KapsamServisi;
use Illuminate\Http\Request;

/**
 * SMS Yönetimi controller'larının ortak tabanı — yetki kapıları, kill-switch, personel kapsamı TEK yerde.
 *
 *   erişim (rapor, şablon, kara liste) : pazarlama.sms_gonder VEYA pazarlama.toplu_sms (eski sayfa kapısı)
 *   gönderim                           : pazarlama.toplu_sms (eski toplusmsgonderme)
 *   ayarlar                            : hesap sahibi (rol 1 — eski blade koşulu)
 *
 * Yazmalar SMS_V2_YAZMA_AKTIF arkasında (kapalıyken 409) — kapsam/yetki SONRASI.
 */
abstract class SmsTabanController extends V2Controller
{
    protected const ERISIM = ['pazarlama.sms_gonder', 'pazarlama.toplu_sms'];

    protected const GONDERIM = 'pazarlama.toplu_sms';

    private const HESAP_SAHIBI = 1;

    protected ?string $yazmaAlani = 'sms';

    protected string $yazmaKapaliMesaj = 'SMS yönetimi v2 yazma şu an kapalı';

    /** @return array{0:int,1:int} [yetkili id, salon id] */
    protected function salon(Request $request): array
    {
        return $this->salonDenetle($request, self::ERISIM);
    }

    /** @return array{0:int,1:int} [yetkili id, salon id] */
    protected function hesapSahibiSalon(Request $request): array
    {
        [$uid, $salonId] = $this->salon($request);
        if (! $this->hesapSahibiMi($uid, $salonId)) {
            throw new YetkisizErisimException('SMS ayarlarını yalnızca hesap sahibi değiştirebilir');
        }

        return [$uid, $salonId];
    }

    protected function hesapSahibiMi(int $uid, int $salonId): bool
    {
        return in_array(self::HESAP_SAHIBI, app(KapsamServisi::class)->salonRolleri($uid, $salonId), true);
    }

    /** musteri.tum_portfoy_gor yoksa kendi portföyü. */
    protected function kisit(int $uid, int $salonId): ?int
    {
        return app(PortfoyKapsami::class)->kisitliPersonel($uid, $salonId);
    }

    protected function telefonGor(int $uid, int $salonId): bool
    {
        return $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor');
    }
}
