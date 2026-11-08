<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\Randevular;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use App\Services\BildirimKuyrugu\Randevu\RandevuMesajKanali;
use Illuminate\Support\Facades\Log;

/**
 * dogrulama_kodu: eski ApiController::dogrulama_kodu_gonder. Kodu uretir, randevuya yazar, gonderir. WhatsApp oncelikli (sistem
 * kurali): bagliysa acil WhatsApp, gidemezse SMS. Eskiden SMS kredisi varsa once SMS'ti ve paylasilan oturumlu alt salon bagli
 * sayilmiyordu; ikisi duzeltildi. Yeniden deneme yalniz saglayiciya ulasilamazsa olur (SmsKanali).
 */
class DogrulamaKoduIsleyici extends RandevuTabanliIsleyici
{
    public function __construct(
        private WhatsappKanali $whatsapp,
        private RandevuMesajKanali $kanal,
    ) {}

    protected function randevuIsle(object $kayit, array $payload, Randevular $randevu): void
    {
        $kod = substr(str_shuffle('1234567890'), 0, 4);
        $randevu->dogrulama = $kod;
        $randevu->save();

        $telefon = $randevu->users->cep_telefon ?? null;
        $salonId = $randevu->salon_id;
        $mesajMetni = 'Doğrulama kodunuz : '.$kod;
        $mesaj = [['to' => $telefon, 'message' => $mesajMetni]];

        $salon = $this->whatsapp->salon($salonId);
        if ($salon && $telefon && $this->kanal->whatsappAcikMi($salon) && $this->whatsappIleGitti($salon, $telefon, $mesajMetni, $randevu)) {
            return;
        }

        $this->kanal->sms($mesaj, $salonId, true);
    }

    private function whatsappIleGitti(object $salon, mixed $telefon, string $mesajMetni, Randevular $randevu): bool
    {
        $baglam = ['salon_id' => $randevu->salon_id, 'randevu_id' => $randevu->id];
        try {
            $sonuc = $this->whatsapp->acilGonder($salon, $telefon, $mesajMetni, $randevu->user_id, 'geldi_dogrulama_kodu');
            if (! empty($sonuc['ok'])) {
                Log::info('[GELDI-KOD] WA gonderildi', $baglam + ['logId' => $sonuc['logId'] ?? null]);

                return true;
            }
            Log::warning('[GELDI-KOD] WA basarisiz -> SMS son care', $baglam + ['err' => $sonuc['error'] ?? 'unknown']);
        } catch (\Throwable $e) {
            Log::warning('[GELDI-KOD] WA istisna -> SMS son care: '.$e->getMessage(), $baglam);
        }

        return false;
    }
}
