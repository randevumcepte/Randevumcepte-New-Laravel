<?php

namespace App\Domain\Anket;

/**
 * Tek gönderimin puan yorumu — SAF. NPS sınıfı eski blade ve Flutter'da ayrı ayrı hesaplanıyordu.
 *
 * Web sitesinde yayın eşiği sabittir ve mini sitenin sorgusuyla (salondetaylari.blade) aynıdır:
 * cevaplanmış + yorumu var + (NPS ≥ 9 veya CSAT ≥ 4.5). Salonun Google eşiğinden bağımsızdır.
 */
final class AnketPuanlama
{
    public const NPS_PROMOTER = 9;
    public const NPS_PASIF = 7;
    public const WEB_CSAT_ESIK = 4.5;

    /** promoter (9-10) | pasif (7-8) | detractor (0-6) | null (NPS cevabı yok) */
    public static function npsSinifi(?int $nps): ?string
    {
        if ($nps === null) {
            return null;
        }

        return $nps >= self::NPS_PROMOTER ? 'promoter' : ($nps >= self::NPS_PASIF ? 'pasif' : 'detractor');
    }

    public static function webeUygunMu(bool $cevaplandi, ?string $yorum, ?int $nps, ?float $csat): bool
    {
        return $cevaplandi
            && trim((string) $yorum) !== ''
            && (($nps !== null && $nps >= self::NPS_PROMOTER) || ($csat !== null && $csat >= self::WEB_CSAT_ESIK));
    }
}
