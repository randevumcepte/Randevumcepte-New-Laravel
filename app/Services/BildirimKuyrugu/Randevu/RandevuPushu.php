<?php

namespace App\Services\BildirimKuyrugu\Randevu;

use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use Illuminate\Support\Facades\Log;

/**
 * Randevuya bagli push'un eski kalibi: tip, baslik, govde, randevu, deep link appointment_detail. Hata eskideki gibi yutulur,
 * log'a yazilir; kayit yeniden denenmez, boylece giden WA/SMS tekrarlanmaz. Firebase anahtari eksikse de push boyle duser.
 */
final class RandevuPushu
{
    /**
     * @param  string  $hataLogu  eski log metni; sonuna ': hata' eklenir
     * @param  array  $ekstra  eski ->extra([...])
     * @param  array  $deepLinkEk  randevu_id'den sonra deep link'e eklenen parametreler
     */
    public static function yolla(PushBildirimi $alici, string $tip, string $baslik, string $govde, int|string $randevuId, string $hataLogu, array $ekstra = [], array $deepLinkEk = []): void
    {
        try {
            $alici->tip($tip)
                ->baslik($baslik)
                ->govde($govde)
                ->randevu((int) $randevuId)
                ->deepLink('appointment_detail', array_merge(['randevu_id' => $randevuId], $deepLinkEk))
                ->ekstra($ekstra)
                ->gonder();
        } catch (\Throwable $e) {
            Log::warning($hataLogu.': '.$e->getMessage());
        }
    }
}
