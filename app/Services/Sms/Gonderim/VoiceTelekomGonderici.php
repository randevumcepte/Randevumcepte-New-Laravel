<?php

namespace App\Services\Sms\Gonderim;

use App\Domain\Sms\VoiceTelekomPaketi;
use App\Services\Sms\Rapor\SaglayiciHatasi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * VoiceTelekom çoklu SMS — eski istemciyle aynı uç (POST sms/create, Basic auth). Paket başlığı raporda
 * türü belirler. Zaman aşımında paket gitmiş olabilir: çağıran tekrar DENEMEZ (mükerrer ücretli SMS).
 */
class VoiceTelekomGonderici
{
    /**
     * @param  string[]  $numaralar
     * @return string sağlayıcı paket kimliği
     *
     * @throws SaglayiciHatasi
     */
    public function gonder(object $salon, array $numaralar, string $mesaj, string $baslik): string
    {
        $ozelId = 'sms_'.date('Ymd_His').'_'.Str::lower(Str::random(8));
        try {
            $cevap = Http::timeout((int) config('sms.gonderim_zaman_asimi_sn'))
                ->withoutVerifying()
                ->withBasicAuth((string) $salon->sms_user_name, (string) $salon->sms_secret)
                ->acceptJson()
                ->post((string) config('sms.voicetelekom.gonderim_url'), VoiceTelekomPaketi::govde($baslik, $mesaj, $numaralar, (string) $salon->sms_baslik, $ozelId))
                ->json();
        } catch (\Throwable $e) {
            throw new SaglayiciHatasi('SMS sağlayıcısına ulaşılamadı: '.$e->getMessage());
        }
        if (! is_array($cevap) || ($cevap['err'] ?? null) !== null || empty($cevap['data']['pkgID'])) {
            throw new SaglayiciHatasi((string) ($cevap['err']['message'] ?? 'SMS sağlayıcısı beklenmeyen cevap verdi'));
        }

        return (string) $cevap['data']['pkgID'];
    }
}
