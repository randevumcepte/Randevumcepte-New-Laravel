<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use App\Domain\Sms\VoiceTelekomPaketi;
use App\Services\Sms\Gonderim\VoiceTelekomGonderici;

/**
 * VoiceTelekom cevabi eski SmsApi::sendMultiSms sozlesmesiyle: istisna atmaz, {pkgID, err:{status,code,message}} doner.
 * Eski cagri yerleri hata koduna gore karar verir (ERR_SMS_PKG_DUPLICATION). customID null ise govdeye yazilmaz; eski
 * Controller::sms_gonder ve sms_gonder_2 yollari hic gondermez.
 */
class VoiceTelekomIstemcisi
{
    public function __construct(private VoiceTelekomGonderici $gonderici) {}

    /**
     * @param  string[]  $numaralar
     * @return array{pkgID:?string, err:?array{status:int, code:string, message:string}}
     */
    public function gonder(object $salon, string $baslik, string $icerik, array $numaralar, ?string $ozelId = null): array
    {
        $govde = VoiceTelekomPaketi::govde($baslik, $icerik, $numaralar, (string) $salon->sms_baslik, (string) $ozelId);
        if ($ozelId === null) {
            unset($govde['customID']);
        }

        try {
            $cevap = $this->gonderici->istek($salon, $govde);
            $sonuc = $cevap->json();
            if (! is_array($sonuc)) {
                return $this->hata(500, 'JSON_ERROR_SYNTAX', 'JSON_ERROR_SYNTAX');
            }
            if (($sonuc['err'] ?? null) !== null) {
                return $this->hata($cevap->status(), (string) ($sonuc['err']['code'] ?? ''), (string) ($sonuc['err']['message'] ?? ''));
            }
            if (empty($sonuc['data']['pkgID'])) {
                return $this->hata(500, 'Undefined pkgID', 'Undefined pkgID');
            }

            return ['pkgID' => (string) $sonuc['data']['pkgID'], 'err' => null];
        } catch (\Throwable $e) {
            return $this->hata(500, $e->getMessage(), $e->getMessage());
        }
    }

    private function hata(int $durum, string $kod, string $mesaj): array
    {
        return ['pkgID' => null, 'err' => ['status' => $durum, 'code' => $kod, 'message' => $mesaj]];
    }
}
