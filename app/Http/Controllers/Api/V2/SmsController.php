<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Sms\SmsTuru;
use App\Services\Sms\Bakiye\SmsBakiyeServisi;
use App\Services\Sms\Rapor\SmsRaporServisi;
use App\Services\Sms\SmsAcilisServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * SMS YÖNETİMİ v2 — açılış, bakiye, raporlar. Eşleme: docs/strangler-sms-yonetimi.md
 *
 *   GET /sms/acilis?salon_id=                 → sekmeler, yetkiler, sözlükler, bakiye, şablonlar, ayarlar
 *   GET /sms/bakiye?salon_id=                 → {kalan_sms}
 *   GET /sms/raporlar?salon_id=&tur=bildirim&yenile=1 + ListeIstegi → {data[], uyari, …meta}
 *   GET /sms/raporlar/{paket}?salon_id=       → {kayitlar[], uyari}
 */
class SmsController extends SmsTabanController
{
    public function __construct(private SmsRaporServisi $rapor) {}

    public function acilis(Request $request, SmsAcilisServisi $acilis)
    {
        [$uid, $salonId] = $this->salon($request);

        return ApiCevap::basarili($acilis->veri($salonId, [
            'gonder'      => $this->yetkiVar($uid, $salonId, self::GONDERIM),
            'ayar'        => $this->hesapSahibiMi($uid, $salonId),
            'telefon_gor' => $this->telefonGor($uid, $salonId),
            'yazabilir'   => (bool) config('sms.yazma_aktif'),
        ]));
    }

    public function bakiye(Request $request, SmsBakiyeServisi $bakiye)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili(['kalan_sms' => $bakiye->kalan($salonId)]);
    }

    public function raporlar(Request $request)
    {
        [, $salonId] = $this->salon($request);
        $liste = ListeIstegi::istekten($request, 10, ['tarih', 'adet', 'kredi'], 'tarih', 'desc')->dizi();

        return ApiCevap::basarili($this->rapor->paketler(
            $salonId, SmsTuru::koddan((string) $request->input('tur', SmsTuru::Bildirim->kod())), $liste, $request->boolean('yenile'),
        ));
    }

    public function raporDetay(Request $request, string $paket)
    {
        [$uid, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->rapor->alicilar($salonId, $paket, $this->telefonGor($uid, $salonId)));
    }
}
