<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\Randevular;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use App\Services\BildirimKuyrugu\Randevu\RandevuMesajKanali;
use App\Services\BildirimKuyrugu\Randevu\SmsKredisi;
use Illuminate\Support\Facades\Log;

/**
 * dogrulama_kodu: eski ApiController::dogrulama_kodu_gonder. Kodu uretir, randevuya yazar, gonderir. Bu turde sira SMS-first:
 *   1) SMS kredisi varsa SMS; 2) yoksa ve WA bagliysa WA; 3) WA olmadi ise son care SMS.
 * Eskiden gelen ve KORUNAN iki durum: WA bagli mi kontrolu salonun kendi kolonlarindadir (paylasilan oturumlu alt salon bagli
 * sayilmaz); yeniden denemede yeni kod uretilir, musteriye farkli iki kod gidebilir.
 */
class DogrulamaKoduIsleyici extends RandevuTabanliIsleyici
{
    public function __construct(
        private WhatsappKanali $whatsapp,
        private RandevuMesajKanali $kanal,
        private SmsKredisi $kredi,
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

        if ($this->kredi->kalan($salonId) > 0) {
            $this->kanal->sms($mesaj, $salonId, true);

            return;
        }

        $salon = $this->whatsapp->salon($salonId);
        if ($salon && $this->whatsapp->bagliMi($salon) && $telefon && $this->whatsappIleGitti($salon, $telefon, $mesajMetni, $randevu)) {
            return;
        }

        // Son care: kredi sifir bile olsa saglayici bazi planlarda hala gonderebilir.
        $this->kanal->sms($mesaj, $salonId, true);
    }

    private function whatsappIleGitti(object $salon, mixed $telefon, string $mesajMetni, Randevular $randevu): bool
    {
        $baglam = ['salon_id' => $randevu->salon_id, 'randevu_id' => $randevu->id];
        try {
            $sonuc = $this->whatsapp->acilGonder($salon, $telefon, $mesajMetni, $randevu->user_id, 'geldi_dogrulama_kodu');
            if (! empty($sonuc['ok'])) {
                Log::info('[GELDI-KOD] WA gonderildi (SMS kredisi yok)', $baglam + ['logId' => $sonuc['logId'] ?? null]);

                return true;
            }
            Log::warning('[GELDI-KOD] WA basarisiz -> SMS son care', $baglam + ['err' => $sonuc['error'] ?? 'unknown']);
        } catch (\Throwable $e) {
            Log::warning('[GELDI-KOD] WA istisna -> SMS son care: '.$e->getMessage(), $baglam);
        }

        return false;
    }
}
