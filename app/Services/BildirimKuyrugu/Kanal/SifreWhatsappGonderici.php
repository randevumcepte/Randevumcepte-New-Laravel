<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use Illuminate\Support\Facades\Log;

/**
 * Sifre mesajini once WhatsApp'tan anlik gonderir; eski ApiController::sifreWhatsappGonder. Tipi 'sifre_sifirlama': kara liste
 * ve gunluk tavandan muaf, kontor dusmez. Gidemeyen mesajlari doner, cagiran yalniz onlari SMS'e verir.
 *
 * Outbox isteginde appBundle yoktur; eski kodun appBundle dallari (salon 20, marka salonu) calismaz, salon parametreden gelir.
 *
 * Eskiden gelen ve KORUNAN hata: $userId personel akisinda isletmeyetkilileri.id'dir ama log satirina user_id olarak yazilir.
 */
class SifreWhatsappGonderici
{
    public function __construct(private WhatsappKanali $whatsapp) {}

    /**
     * @param  array<int, array{to?:mixed, message?:string, wa_message?:string}>  $mesajlar
     * @return array SMS'e dusecek mesajlar: WhatsApp kapaliysa hepsi, aciksa yalniz gidemeyenler (WhatsApp'tan giden SMS almaz)
     */
    public function gonder(int|string|null $salonId, array $mesajlar, int|string|null $userId = null): array
    {
        try {
            $salon = $salonId ? $this->whatsapp->salon($salonId) : null;
            $oturumSalonu = $salon ? $this->whatsapp->oturumSalonu($salon) : null;
            Log::info('[Sifre WA] kanal karari', [
                'belirlenen_salon_id' => $salonId,
                'wa_session_salon_id' => $oturumSalonu->id ?? null,
                'salon_var_mi'        => (bool) $salon,
                'wa_aktif'            => $oturumSalonu ? (int) ($oturumSalonu->whatsapp_aktif ?? 0) : null,
                'wa_durum'            => $oturumSalonu->whatsapp_durum ?? null,
            ]);

            if (! $oturumSalonu || ! $this->whatsapp->bagliMi($oturumSalonu)) {
                return $mesajlar;
            }
        } catch (\Throwable $e) {
            Log::warning('[Sifre WA] istisna, SMS fallback', ['err' => $e->getMessage()]);

            return $mesajlar;
        }

        return $this->whatsapp->gonderilemeyenler($mesajlar, function (array $m) use ($salon, $userId) {
            $sonuc = $this->whatsapp->acilGonder($salon, $m['to'], $m['wa_message'] ?? $m['message'], $userId, 'sifre_sifirlama');
            ($sonuc['ok'] ?? false)
                ? Log::info('[Sifre WA] anlik kuyruga eklendi', ['salon_id' => $salon->id, 'logId' => $sonuc['logId'] ?? null])
                : Log::warning('[Sifre WA] gonderim basarisiz -> SMS fallback', ['salon_id' => $salon->id, 'err' => $sonuc['error'] ?? 'unknown']);

            return $sonuc;
        }, 'Sifre WA');
    }
}
