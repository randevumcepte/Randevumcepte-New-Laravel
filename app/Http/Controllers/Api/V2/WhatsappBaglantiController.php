<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Whatsapp\WhatsappBaglantiServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * WhatsApp oturum yönetimi (eski web + mobil whatsapp/{durum,qr,baslat,pair-phone,cikis}).
 *
 *   GET  /whatsapp/durum              {status, ad, renk, phone, bagli, lastError, connectedAt, paylasilan}
 *   GET  /whatsapp/qr                 {qr: PNG data URI | null}
 *   POST /whatsapp/baslat             taze oturum → durum
 *   POST /whatsapp/pair-phone {phone} {code} (QR alternatifi; oturumu kendisi başlatır)
 *   POST /whatsapp/cikis
 */
class WhatsappBaglantiController extends WhatsappTabanController
{
    public function __construct(private WhatsappBaglantiServisi $baglanti) {}

    public function durum(Request $request)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->baglanti->durum($salonId));
    }

    public function qr(Request $request)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->baglanti->qr($salonId));
    }

    public function baslat(Request $request)
    {
        [$uid, $salonId] = $this->yazilacakSalon($request);

        return ApiCevap::basarili($this->baglanti->baslat($salonId, $uid));
    }

    public function pairPhone(Request $request)
    {
        [, $salonId] = $this->yazilacakSalon($request);
        $v = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        return ApiCevap::basarili($this->baglanti->eslestir($salonId, $v['phone']));
    }

    public function cikis(Request $request)
    {
        [$uid, $salonId] = $this->yazilacakSalon($request);
        $this->baglanti->cikis($salonId, $uid);

        return ApiCevap::basarili(null, 'Oturum kapatıldı');
    }
}
