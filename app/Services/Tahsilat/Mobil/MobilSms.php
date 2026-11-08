<?php

namespace App\Services\Tahsilat\Mobil;

use App\Support\BildirimKuyrugu;

/**
 * Mobil para akislarindaki musteri bilgilendirmesi (eski sms_gonder_2). Bildirim kuyruguna 'sms' olarak yazilir: WhatsApp
 * oncelikli, olmazsa salonun saglayicisindan SMS, kara liste uygulanir (SmsIsleyici). Islemi bozmaz, transaction disinda cagrilir.
 */
class MobilSms
{
    public function gonder(int $salonId, ?string $telefon, string $mesaj): void
    {
        if (! $telefon) {
            return;
        }
        BildirimKuyrugu::sms($salonId, null, [['to' => $telefon, 'message' => $mesaj]]);
    }
}
