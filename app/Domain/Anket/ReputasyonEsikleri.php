<?php

namespace App\Domain\Anket;

/**
 * Reputation Booster eşikleri (salonlar) — SAF, TEK KAYNAK: varsayılanlar + izin verilen aralık.
 *   google_review_esik_*       : bu puan ve üstü Google yorumuna yönlendirilir
 *   kotu_puan_uyari_esik_*     : bu puan ve altı "düşük puan" sayılır
 * Eskide varsayılanlar 5 yerde yazılıydı ve Flutter yedeğinde CSAT 3.0, sunucuda 2.5 idi.
 */
final class ReputasyonEsikleri
{
    public const GOOGLE_NPS = 9;
    public const GOOGLE_CSAT = 4.5;
    public const UYARI_NPS = 6;
    public const UYARI_CSAT = 2.5;

    private function __construct(
        public readonly int $googleNps,
        public readonly float $googleCsat,
        public readonly int $uyariNps,
        public readonly float $uyariCsat,
    ) {}

    public static function satirdan(object $s): self
    {
        return new self(
            (int) ($s->google_review_esik_nps ?? self::GOOGLE_NPS),
            (float) ($s->google_review_esik_csat ?? self::GOOGLE_CSAT),
            (int) ($s->kotu_puan_uyari_esik_nps ?? self::UYARI_NPS),
            (float) ($s->kotu_puan_uyari_esik_csat ?? self::UYARI_CSAT),
        );
    }

    public static function npsSinirla(mixed $v): int
    {
        return max(0, min(10, (int) $v));
    }

    public static function csatSinirla(mixed $v): float
    {
        return max(1.0, min(5.0, (float) $v));
    }

    public function dizi(): array
    {
        return [
            'google_review_esik_nps'    => $this->googleNps,
            'google_review_esik_csat'   => $this->googleCsat,
            'kotu_puan_uyari_esik_nps'  => $this->uyariNps,
            'kotu_puan_uyari_esik_csat' => $this->uyariCsat,
        ];
    }
}
