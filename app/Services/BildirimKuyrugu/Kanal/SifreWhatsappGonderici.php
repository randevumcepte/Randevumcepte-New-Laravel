<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use Illuminate\Support\Facades\Log;

/**
 * Sifre mesajini once WhatsApp'tan anlik gonderir; eski ApiController::sifreWhatsappGonder. Tipi 'sifre_sifirlama': kara liste
 * ve gunluk tavandan muaf, kontor dusmez. Basarisizsa false doner, cagiran SMS'e duser.
 *
 * Outbox isteginde appBundle yoktur; eski kodun appBundle dallari (salon 20, marka salonu) calismaz, salon parametreden gelir.
 *
 * Eskiden gelen ve KORUNAN hata: $userId personel akisinda isletmeyetkilileri.id'dir ama log satirina user_id olarak yazilir.
 */
class SifreWhatsappGonderici
{
    public function __construct(private WhatsappKanali $whatsapp) {}

    /** @param  array<int, array{to?:mixed, message?:string, wa_message?:string}>  $mesajlar */
    public function gonder(int|string|null $salonId, array $mesajlar, int|string|null $userId = null): bool
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
                return false;
            }

            $hepsiBasarili = true;
            foreach ($mesajlar as $m) {
                $telefon = $m['to'] ?? null;
                $metin = $m['wa_message'] ?? $m['message'] ?? null;
                if (! $telefon || ! $metin) {
                    $hepsiBasarili = false;

                    continue;
                }
                $sonuc = $this->whatsapp->acilGonder($salon, $telefon, $metin, $userId, 'sifre_sifirlama');
                if ($sonuc['ok'] ?? false) {
                    Log::info('[Sifre WA] anlik kuyruga eklendi', ['salon_id' => $salon->id, 'logId' => $sonuc['logId'] ?? null]);
                } else {
                    $hepsiBasarili = false;
                    Log::warning('[Sifre WA] gonderim basarisiz -> SMS fallback', ['salon_id' => $salon->id, 'err' => $sonuc['error'] ?? 'unknown']);
                }
            }

            return $hepsiBasarili;
        } catch (\Throwable $e) {
            Log::warning('[Sifre WA] istisna, SMS fallback', ['err' => $e->getMessage()]);

            return false;
        }
    }
}
