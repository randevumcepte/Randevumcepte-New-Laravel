<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Whatsapp\WhatsappPaketServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Mobil WhatsApp "Paket" sekmesi — eski whatsapp/paket-durum, paket-talep, mobil/webview-token.
 *
 *   GET  /whatsapp/paket                      {paket, periyot, baslangic, bitis, deneme, kalan_gun}
 *   POST /whatsapp/paket/talep {paket, periyot, iletisim?}
 *   GET  /whatsapp/kontor/sayfa-baglantisi    {url, expires_in} kontor WebView'i icin 2 dk gecerli imzali adres
 */
class WhatsappPaketController extends WhatsappTabanController
{
    private const SAYFA_DAKIKA = 2;

    public function __construct(private WhatsappPaketServisi $paket) {}

    public function durum(Request $request)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->paket->durum($salonId));
    }

    public function talep(Request $request)
    {
        [$uid, $salonId] = $this->yazilacakSalon($request);
        $v = $request->validate([
            'paket'    => ['required', Rule::in(WhatsappPaketServisi::PAKETLER)],
            'periyot'  => ['required', Rule::in(WhatsappPaketServisi::PERIYOTLAR)],
            'iletisim' => ['nullable', 'string', 'max:500'],
        ]);
        $this->paket->talep($salonId, $v['paket'], $v['periyot'], trim((string) ($v['iletisim'] ?? '')));

        return ApiCevap::basarili(null, WhatsappPaketServisi::TALEP_MESAJI);
    }

    public function kontorSayfasi(Request $request)
    {
        [$uid, $salonId] = $this->salon($request);

        return ApiCevap::basarili([
            'url'        => url(URL::temporarySignedRoute('v2.whatsapp-kontor.sayfa', now()->addMinutes(self::SAYFA_DAKIKA), ['uid' => $uid, 'salon' => $salonId], false)),
            'expires_in' => self::SAYFA_DAKIKA * 60,
        ]);
    }
}
