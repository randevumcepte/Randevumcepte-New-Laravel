<?php

namespace App\Services\Tahsilat\Mobil;

use App\Services\Sms\Gonderim\AnlikSmsGonderici;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Eski sms_gonder_2'nin mobil para akislarindaki kullanimi: salon hesabindan tek SMS, kara listedeki musteriye gitmez,
 * gonderim hatasi islemi bozmaz (eski kod da yalniz loglar). Transaction disinda cagrilir.
 */
class MobilSms
{
    private const ZAMAN_ASIMI_SN = 10;

    public function __construct(private AnlikSmsGonderici $gonderici) {}

    public function gonder(int $salonId, ?string $telefon, string $mesaj): void
    {
        if (! $telefon) {
            return;
        }
        $karaListede = DB::table('musteri_portfoy as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.salon_id', $salonId)->where('u.cep_telefon', $telefon)->where('p.kara_liste', 1)->exists();
        if ($karaListede) {
            return;
        }
        try {
            $salon = DB::table('salonlar')->where('id', $salonId)->first();
            if ($salon) {
                $this->gonderici->gonder($salon, $telefon, $mesaj, (string) $salon->sms_baslik, self::ZAMAN_ASIMI_SN);
            }
        } catch (\Throwable $e) {
            Log::warning('mobil para SMS gonderilemedi', ['salon_id' => $salonId, 'err' => $e->getMessage()]);
        }
    }
}
