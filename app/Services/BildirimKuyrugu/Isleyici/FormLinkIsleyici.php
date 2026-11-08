<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\Kanal\MesajListesi;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Form/sozlesme linki; eski ApiController::_formLinkGonder. Salon WhatsApp'a bagliysa mesaj basina acil gonderim (tip 'sifre_kodu'),
 * basarisiz olan SMS'e duser; SMS tur=6, dogrulama=true (iletim raporuna yazilmaz).
 *
 * Eskiden gelen ve KORUNAN hatalar: baglanti kontrolu salonun kendi satirinda yapilir, paylasilan oturumlu alt salon SMS'e duser.
 * WA dongusunde istisna olursa WA'ya girmis mesajlar dahil tum liste SMS'e gider.
 */
class FormLinkIsleyici implements BildirimIsleyici
{
    private const SMS_TURU = 6;

    public function __construct(private WhatsappKanali $whatsapp, private SmsKanali $sms) {}

    public function isle(object $kayit, array $payload): void
    {
        $mesajlar = MesajListesi::gecerliler($payload);
        if (! $mesajlar) {
            throw new RuntimeException('form_link: mesaj yok');
        }

        $salonId = (int) $kayit->salon_id;
        $kalan = $this->whatsappIleGonder($salonId, $mesajlar, $payload['user_id'] ?? null);
        if ($kalan) {
            $this->sms->gonder2($kalan, ! empty($payload['sms_bildirim']), self::SMS_TURU, true, $salonId, false);
        }
    }

    /** @return array SMS'e dusecek mesajlar */
    private function whatsappIleGonder(int $salonId, array $mesajlar, int|string|null $userId): array
    {
        try {
            $salon = $this->whatsapp->salon($salonId);
            if (! $salon || ! $this->whatsapp->bagliMi($salon)) {
                return $mesajlar;
            }
        } catch (\Throwable $e) {
            Log::error('_formLinkGonder WA hatasi: '.$e->getMessage());

            return $mesajlar;
        }

        return $this->whatsapp->gonderilemeyenler($mesajlar, fn (array $m) => $this->whatsapp->acilGonder($salon, $m['to'], $m['message'], $userId), 'Form WA');
    }
}
