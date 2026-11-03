<?php

namespace App\Services\Sms\Bakiye;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Salonun kalan SMS bakiyesi — saglayici secimi + onbellek TEK yerde.
 * Basarisiz okuma onbellege yazilmaz (bir sonraki istek yeniden dener).
 */
class SmsBakiyeServisi
{
    public function __construct(
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

        $salon = DB::table('salonlar')->where('id', $salonId)
            ->first(['id', 'yeni_sms', 'sms_user_name', 'sms_secret', 'sms_apikey']);
        $kalan = $salon ? $this->saglayici($salon)->bakiye($salon) : null;
        if ($kalan !== null) {
            Cache::put($anahtar, $kalan, (int) config('sms.bakiye_cache_sn', 120));
        }

        return $kalan;
    }

    private function saglayici(object $salon): SmsBakiyeSaglayici
    {
        return (int) ($salon->yeni_sms ?? 0) === 1 ? $this->voiceTelekom : $this->efetech;
    }
}
