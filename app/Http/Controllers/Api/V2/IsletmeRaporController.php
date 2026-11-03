<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Satis\KalemTuru;
use App\Services\Rapor\IsletmeRaporServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * ISLETME RAPORLARI ("Performans Panosu") — eski isletmeyonetim/api/isletmeraporlari/*
 * uclarinin v2 karsiligi (mobilde karsiligi yoktu; Flutter ayni uclari kullanabilir).
 *
 *   GET /rapor/isletme/{ozet|hizmet|urun|personel|musteri|randevu}?salon_id=&donem=&baslangic=&bitis=
 *   (donem varsayilani: bugun — eski ekranla ayni)
 *
 * KVKK: kimlik + kapsam + rol 5 degil (eski sayfa personeli yonlendiriyordu) + sekme yetkisi:
 * rapor.satis (ozet/hizmet/urun/randevu), rapor.personel_performans, rapor.musteri.
 */
class IsletmeRaporController extends RaporTabanController
{
    private const DONEM = 'bugun';

    public function __construct(private IsletmeRaporServisi $servis) {}

    public function ozet(Request $r)
    {
        [, $salonId] = $this->salonYetki($r, 'rapor.satis', true);

        return ApiCevap::basarili($this->servis->ozet($salonId, $this->donem($r, self::DONEM)));
    }

    public function hizmet(Request $r)
    {
        [, $salonId] = $this->salonYetki($r, 'rapor.satis', true);

        return ApiCevap::basarili($this->servis->kalemler($salonId, $this->donem($r, self::DONEM), KalemTuru::Hizmet));
    }

    public function urun(Request $r)
    {
        [, $salonId] = $this->salonYetki($r, 'rapor.satis', true);

        return ApiCevap::basarili($this->servis->kalemler($salonId, $this->donem($r, self::DONEM), KalemTuru::Urun));
    }

    public function personel(Request $r)
    {
        [, $salonId] = $this->salonYetki($r, 'rapor.personel_performans', true);

        return ApiCevap::basarili($this->servis->personeller($salonId, $this->donem($r, self::DONEM)));
    }

    public function musteri(Request $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'rapor.musteri', true);

        return ApiCevap::basarili($this->servis->musteriler($salonId, $this->donem($r, self::DONEM), $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor')));
    }

    public function randevu(Request $r)
    {
        [, $salonId] = $this->salonYetki($r, 'rapor.satis', true);

        return ApiCevap::basarili($this->servis->randevular($salonId, $this->donem($r, self::DONEM)));
    }
}
