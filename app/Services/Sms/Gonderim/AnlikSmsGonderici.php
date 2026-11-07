<?php

namespace App\Services\Sms\Gonderim;

use App\Domain\Sms\SmsSaglayici;
use App\Services\Sms\Rapor\SaglayiciHatasi;
use Illuminate\Support\Facades\Http;

/**
 * Salon hesabindan anlik tek SMS (dogrulama kodu): kod tabloya yazilmaz, kullanici ekranda bekler.
 * Saglayici kurali SmsSaglayici'da. Tekrar DENEMEZ (mukerrer ucretli SMS).
 *
 * @throws SaglayiciHatasi
 */
class AnlikSmsGonderici
{
    public function __construct(private VoiceTelekomGonderici $voiceTelekom) {}

    public function gonder(object $salon, string $telefon, string $mesaj, string $baslik, int $zamanAsimiSn): void
    {
        if (SmsSaglayici::salondan($salon) === SmsSaglayici::VoiceTelekom) {
            $this->voiceTelekom->gonder($salon, [$telefon], $mesaj, $baslik, $zamanAsimiSn);

            return;
        }

        $this->efetech($salon, $telefon, $mesaj, $zamanAsimiSn);
    }

    private function efetech(object $salon, string $telefon, string $mesaj, int $zamanAsimiSn): void
    {
        try {
            $cevap = Http::timeout($zamanAsimiSn)->acceptJson()
                ->withHeaders(['Authorization' => 'Key '.$salon->sms_apikey])
                ->post((string) config('sms.efetech.gonderim_url'), [
                    'originator' => $salon->sms_baslik,
                    'messages'   => [['to' => $telefon, 'message' => $mesaj]],
                    'encoding'   => 'auto',
                ])->json();
        } catch (\Throwable $e) {
            throw new SaglayiciHatasi('SMS sağlayıcısına ulaşılamadı: '.$e->getMessage());
        }
        if (! is_array($cevap) || empty($cevap['response'])) {
            throw new SaglayiciHatasi('SMS sağlayıcısı beklenmeyen cevap verdi');
        }
    }
}
