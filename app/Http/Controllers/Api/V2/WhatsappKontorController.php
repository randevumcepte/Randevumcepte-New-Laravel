<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Whatsapp\KontorPaketleri;
use App\Services\Whatsapp\WhatsappKontorServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WhatsApp kontörü (eski whatsapp.blade kontör bölümü + whatsapp_kontor_mobil WebView).
 *
 *   GET  /whatsapp/kontor                     bakiye, seviye, bant metinleri, uyari, paketler[]
 *   POST /whatsapp/kontor/talep {paket, iletisim?}  sistem sahibine bildirim (outbox)
 */
class WhatsappKontorController extends WhatsappTabanController
{
    private const NOT_UZUNLUK = 200;

    public function __construct(private WhatsappKontorServisi $kontor) {}

    public function durum(Request $request)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->kontor->durum($salonId));
    }

    public function talep(Request $request)
    {
        [$uid, $salonId] = $this->yazilacakSalon($request);
        $v = $request->validate([
            'paket'    => ['required', 'string', Rule::in(KontorPaketleri::kodlar())],
            'iletisim' => ['nullable', 'string', 'max:'.self::NOT_UZUNLUK],
        ]);
        $this->kontor->talep($salonId, $uid, $v['paket'], $v['iletisim'] ?? null);

        return ApiCevap::basarili(null, 'Talebiniz alındı. En kısa sürede sizinle iletişime geçeceğiz.');
    }
}
