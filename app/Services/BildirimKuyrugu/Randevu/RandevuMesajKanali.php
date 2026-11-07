<?php

namespace App\Services\BildirimKuyrugu\Randevu;

use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;

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
     * alicisi/metni bos mesaj SMS listesine duser. Istisna firlatabilir; yakalama ve yedek davranisi cagiranda (ikisi farkli).
     */
    public function whatsappOncelikli(int|string $salonId, array $mesajlar, int|string|null $randevuId, string $gonderimTipi): void
    {
        $salon = $this->whatsapp->salon($salonId);

        $smsKalan = $mesajlar;
        if ($this->whatsappAcikMi($salon)) {
            $smsKalan = [];
            foreach ($mesajlar as $m) {
                $to = $m['to'] ?? null;
                $msg = $m['message'] ?? null;
                if (! $to || ! $msg) {
                    $smsKalan[] = $m;

                    continue;
                }
                $sonuc = $this->whatsapp->gonder($salon, $to, $m['wa_message'] ?? $msg, $randevuId, null, null, false, $gonderimTipi);
                if (! ($sonuc['ok'] ?? false)) {
                    $smsKalan[] = $m;
                }
            }
        }

        if (! empty($smsKalan)) {
            $this->sms($smsKalan, $salonId);
        }
    }
}
