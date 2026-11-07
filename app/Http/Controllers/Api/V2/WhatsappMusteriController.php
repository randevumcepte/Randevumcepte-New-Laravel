<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Iletisim\MusteriKartiYetkisi;
use App\Services\Whatsapp\MusteriMesajServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Müşteri kartından WhatsApp — yönetim sayfasından ayrı yetki: kartı açabilen personel de gönderir.
 *
 *   GET  /whatsapp/musteri-durum?salon_id=&user_id=   {bagli, bağlantılar, musteri_onay}
 *   POST /whatsapp/musteri-mesaj {salon_id, user_id, mesaj}   outbox; engel varsa 409/422
 */
class WhatsappMusteriController extends WhatsappTabanController
{
    private const KURAL = [self::YETKI, ...MusteriKartiYetkisi::ANAHTARLAR];

    public function __construct(private MusteriMesajServisi $mesajlar) {}

    public function durum(Request $request)
    {
        [, $salonId] = $this->salonDenetle($request, self::KURAL);
        $v = $request->validate(['user_id' => ['nullable', 'integer', 'min:1']]);

        return ApiCevap::basarili($this->mesajlar->durum($salonId, isset($v['user_id']) ? (int) $v['user_id'] : null));
    }

    public function gonder(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::KURAL);
        $this->yazmaAcikMi();
        $v = $request->validate(['user_id' => ['required', 'integer', 'min:1'], 'mesaj' => ['nullable', 'string']]);
        $sonuc = $this->mesajlar->gonder($salonId, (int) $v['user_id'], $uid, $v['mesaj'] ?? null);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }
}
