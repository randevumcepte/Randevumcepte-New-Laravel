<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Sms\SmsAyarKatalogu;
use App\Services\Sms\SmsAyarServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * SMS bildirim ayarları (yalnız hesap sahibi).
 *
 *   GET   /sms/ayarlar?salon_id=  → {ayarlar:[{ayar_id, baslik, aciklama, hedefler[], musteri, personel}], randevu_sms_hatirlatma, …}
 *   PATCH /sms/ayarlar            {ayarlar:[{ayar_id, musteri?, personel?}], randevu_sms_hatirlatma?}
 */
class SmsAyarController extends SmsTabanController
{
    public function __construct(private SmsAyarServisi $ayar) {}

    public function goster(Request $request)
    {
        [, $salonId] = $this->hesapSahibiSalon($request);

        return ApiCevap::basarili($this->ayar->oku($salonId));
    }

    public function kaydet(Request $request)
    {
        [$uid, $salonId] = $this->hesapSahibiSalon($request);
        $v = $request->validate([
            'ayarlar'                => ['sometimes', 'array'],
            'ayarlar.*'              => ['array'],
            'ayarlar.*.ayar_id'      => ['required', 'integer'],
            'randevu_sms_hatirlatma' => ['sometimes', 'integer', 'min:'.SmsAyarKatalogu::SAAT_MIN, 'max:'.SmsAyarKatalogu::SAAT_MAKS],
        ]);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(
            $this->ayar->kaydet($uid, $salonId, $v['ayarlar'] ?? [], isset($v['randevu_sms_hatirlatma']) ? (int) $v['randevu_sms_hatirlatma'] : null),
            'SMS ayarları başarıyla kaydedildi',
        );
    }
}
