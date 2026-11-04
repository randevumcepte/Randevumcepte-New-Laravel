<?php

namespace App\Domain\Anket;

/** Gönderimin görünen durumu (eski tablo rozeti): cevapladı > süresi doldu > bekliyor. */
enum GonderimDurumu: string
{
    case CEVAPLADI = 'cevapladi';
    case SURE_DOLDU = 'sure_doldu';
    case BEKLIYOR = 'bekliyor';

    public static function belirle(bool $cevaplandi, ?string $sonGecerlilik, string $simdi): self
    {
        if ($cevaplandi) {
            return self::CEVAPLADI;
        }

        return $sonGecerlilik && strtotime($sonGecerlilik) < strtotime($simdi) ? self::SURE_DOLDU : self::BEKLIYOR;
    }

    public function etiket(): string
    {
        return match ($this) {
            self::CEVAPLADI  => 'Cevapladı',
            self::SURE_DOLDU => 'Süre Doldu',
            self::BEKLIYOR   => 'Bekliyor',
        };
    }

    /** .rc-rozet-<renk> */
    public function renk(): string
    {
        return match ($this) {
            self::CEVAPLADI  => 'yesil',
            self::SURE_DOLDU => 'gri',
            self::BEKLIYOR   => 'turuncu',
        };
    }

    public function dizi(): array
    {
        return ['kod' => $this->value, 'etiket' => $this->etiket(), 'renk' => $this->renk()];
    }
}
