<?php

namespace App\Domain\Ayar;

/**
 * Takvim boyama renk_id dongusu (renk_duzenleri 10'lu palet) — cihaz, oda ve hizmet kategorisi
 * renk satirlarinin ORTAK kurali (eski panelde uc kopya). SAF.
 */
final class TakvimRengi
{
    public const RENK_SAYISI = 10;

    /** Son kullanilan renk_id'den sonraki (10'dan sonra 1'e doner). */
    public static function sonraki(?int $sonRenkId): int
    {
        return $sonRenkId === null || $sonRenkId >= self::RENK_SAYISI ? 1 : $sonRenkId + 1;
    }
}
