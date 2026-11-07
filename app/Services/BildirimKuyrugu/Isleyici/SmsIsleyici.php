<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\Kanal\MesajListesi;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use RuntimeException;

/**
 * Duz SMS (kasa bildirimleri, onay kodlari); eski ApiController::sms_gonder_bildirimli. dogrulama=true: iletim raporuna yazilmaz.
 * Eski hata KORUNUR: bu yol yalniz Efetech'tir, WhatsApp ve kara liste yoktur (bkz. SmsKanali::apiBildirimli).
 */
class SmsIsleyici implements BildirimIsleyici
{
    public function __construct(private SmsKanali $sms) {}

    public function isle(object $kayit, array $payload): void
    {
        $mesajlar = MesajListesi::gecerliler($payload);
        if (! $mesajlar) {
            throw new RuntimeException('sms: mesaj yok');
        }

        $this->sms->apiBildirimli($mesajlar, false, 1, ! empty($payload['dogrulama']), (int) $kayit->salon_id);
    }
}
