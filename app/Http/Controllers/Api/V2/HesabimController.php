<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Hesabim\FaturaBilgiIstegi;
use App\Services\Ayar\IsletmeBilgiServisi;
use App\Services\Hesabim\HesabimServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * HESABIM & FATURALAR v2 (eski /isletmeyonetim/hesabim + v1 /hesabim) — ince controller.
 *
 *   GET /hesabim?salon_id=                 → sekmeler, isletme, kullanici, hizmetler, faturalar,
 *                                            sms_siparisleri, sms_paketleri, uyelik_url
 *   PUT /hesabim/fatura-bilgisi?salon_id=  {vergi_adi, vergi_no, vergi_adresi, kdv_orani?}
 *
 * Personel (rol 5) kapalı: fatura ve vergi bilgisi işletme verisidir. Eski web ucunda hiç denetim
 * yoktu. mobil=1: lisans bitti ekranını mobilde her rol açıyor, bu yolda yalnız kimlik + salon
 * kapsamı aranır. Kayıt tek yazma yolundan (Ayarlar → İşletme Bilgileri servisi) geçer.
 */
class HesabimController extends V2Controller
{
    protected ?string $yazmaAlani = 'hesabim';

    protected string $yazmaKapaliMesaj = 'Hesap kaydı henüz etkin değil (HESABIM_V2_YAZMA_AKTIF kapalı)';

    private const KAPALI_ROLLER = [5];

    public function __construct(
        private HesabimServisi $hesabim,
        private IsletmeBilgiServisi $isletme,
    ) {}

    public function goster(Request $request)
    {
        [$uid, $salonId] = $this->denetle($request);

        return ApiCevap::basarili($this->hesabim->getir($uid, $salonId));
    }

    public function faturaBilgisi(FaturaBilgiIstegi $istek)
    {
        [$uid, $salonId] = $this->denetle($istek);
        $this->yazmaAcikMi();
        $this->isletme->kaydet($salonId, $uid, $istek->validated(),
            $istek->boolean('mobil') ? ['fatura_bilgi_guncelle', 'Fatura bilgileri güncellendi'] : IsletmeBilgiServisi::DENETIM);

        return ApiCevap::basarili($istek->validated(), 'Fatura bilgileri güncellendi.');
    }

    /** @return array{0:int,1:int} [yetkili id, salon id] */
    private function denetle(Request $request): array
    {
        [$uid, $salonId] = $this->salonDenetle($request, []);
        if ($request->boolean('mobil')) {
            return [$uid, $salonId];
        }
        app(GuvenlikKapisi::class)->rolDisiDenetle($uid, $salonId, self::KAPALI_ROLLER, 'Hesap bilgilerini yalnızca işletme yöneticileri görebilir');

        return [$uid, $salonId];
    }
}
