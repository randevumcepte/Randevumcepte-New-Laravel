<?php

namespace App\Services\Sms\Bakiye;

use App\Domain\Sms\SmsSaglayici;
use App\Services\Sms\SmsHesapServisi;
use Illuminate\Support\Facades\Cache;

/**
 * Salonun kalan SMS bakiyesi — saglayici secimi + onbellek TEK yerde.
 * Basarisiz okuma onbellege yazilmaz (bir sonraki istek yeniden dener).
 */
class SmsBakiyeServisi
{
    public function __construct(
        private SmsHesapServisi $hesap,
        private VoiceTelekomBakiye $voiceTelekom,
        private EfetechBakiye $efetech,
    ) {}

    public function kalan(int $salonId): ?int
    {
        $anahtar = 'sms.bakiye.'.$salonId;
        $onceki = Cache::get($anahtar);
        if (is_int($onceki)) {
            return $onceki;
        }

        $salon = $this->hesap->bul($salonId);
        $kalan = $salon ? $this->saglayici($salon)->bakiye($salon) : null;
        if ($kalan !== null) {
            Cache::put($anahtar, $kalan, (int) config('sms.bakiye_cache_sn', 120));
        }

        return $kalan;
    }

    private function saglayici(object $salon): SmsBakiyeSaglayici
    {
        return match (SmsSaglayici::salondan($salon)) {
            SmsSaglayici::VoiceTelekom => $this->voiceTelekom,
            SmsSaglayici::Efetech      => $this->efetech,
        };
    }
}
