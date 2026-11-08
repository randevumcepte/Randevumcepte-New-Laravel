<?php

namespace App\Services\BildirimKuyrugu\Randevu;

use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use Illuminate\Support\Facades\Log;

/** Randevu mesajlarinin ortak gonderimi: ApiController::sms_gonder_2 cagri bicimi ve iki metotta ayni olan WA-first dongusu. */
class RandevuMesajKanali
{
    public function __construct(
        private WhatsappKanali $whatsapp,
        private SmsKanali $sms,
    ) {}

    /** Eski sms_gonder_2($request, $mesajlar, false, 1, $dogrulama, $salonId, false). */
    public function sms(array $mesajlar, int|string $salonId, bool $dogrulama = false): void
    {
        $this->sms->gonder2($mesajlar, false, 1, $dogrulama, $salonId, false);
    }

    /** Eski $waKanaliAcik: paylasilan oturumda durum oturum sahibi salonda okunur. */
    public function whatsappAcikMi(?object $salon): bool
    {
        return $salon !== null && $this->whatsapp->bagliMi($this->whatsapp->oturumSalonu($salon));
    }

    /**
     * Eski randevuIptalBildirimleri ve randevuKayitBildirimGonder ortak akisi: kanal aciksa once WA, hemen basarisiz olan ya da
     * alicisi/metni bos mesaj SMS listesine duser. WhatsApp istisnasi yalniz o mesaji SMS'e dusurur (mukerrer olmaz). Yalniz SMS
     * adimi istisna firlatabilir.
     */
    public function whatsappOncelikli(int|string $salonId, array $mesajlar, int|string|null $randevuId, string $gonderimTipi): void
    {
        try {
            $salon = $this->whatsapp->salon($salonId);
            $acik = $this->whatsappAcikMi($salon);
        } catch (\Throwable $e) {
            Log::warning('[Randevu WA] kanal okunamadi, SMS\'e dusuyor', ['salon_id' => $salonId, 'err' => $e->getMessage()]);
            $acik = false;
        }

        $smsKalan = ! $acik ? $mesajlar : $this->whatsapp->gonderilemeyenler($mesajlar,
            fn (array $m) => $this->whatsapp->gonder($salon, $m['to'], $m['wa_message'] ?? $m['message'], $randevuId, null, null, false, $gonderimTipi),
            'Randevu WA');

        if (! empty($smsKalan)) {
            $this->sms($smsKalan, $salonId);
        }
    }
}
