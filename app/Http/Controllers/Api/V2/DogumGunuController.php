<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Ozet\DogumGunuServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Mobil dogum gunu popup'i — eski /dogum-gunu/{bugun,gonder,atla}. Yetkiler eski uclarla ayni. Liste yetkisi yoksa
 * popup her giriste uyari tetiklemesin diye bos liste doner (ekran bunu bekler).
 *
 *   GET  /dogum-gunu/bugun?salon_id=          {liste:[{id,name,cep_telefon,dogum_tarihi,gonderildi}], tarih}
 *   POST /dogum-gunu/gonder {salon_id, musteri_id}  {ok, kanal, mesaj}; ayni gun ikinci gonderim yapilmaz
 *   POST /dogum-gunu/atla   {salon_id, musteri_id}  "Hayir" kalici isaret
 */
class DogumGunuController extends V2Controller
{
    private const LISTE = 'musteri.liste_gor';

    private const GONDERIM = ['pazarlama.whatsapp_gonder', 'pazarlama.sms_gonder'];

    public function __construct(private DogumGunuServisi $dogum) {}

    public function bugun(Request $r)
    {
        [$uid, $salonId] = $this->salonDenetle($r, []);
        if (! $this->yetkiVar($uid, $salonId, self::LISTE)) {
            return ApiCevap::basarili(['liste' => [], 'tarih' => date('Y-m-d')]);
        }

        return ApiCevap::basarili($this->dogum->bugun($salonId, $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor')));
    }

    public function gonder(Request $r)
    {
        [$uid, $salonId] = $this->salonDenetle($r, self::GONDERIM);
        $v = $r->validate(['musteri_id' => ['required', 'integer', 'min:1']]);

        return ApiCevap::basarili($this->dogum->gonder($salonId, (int) $v['musteri_id'], $uid));
    }

    public function atla(Request $r)
    {
        [, $salonId] = $this->salonDenetle($r, self::LISTE);
        $v = $r->validate(['musteri_id' => ['required', 'integer', 'min:1']]);
        $this->dogum->atla($salonId, (int) $v['musteri_id']);

        return ApiCevap::basarili(['ok' => true]);
    }
}
