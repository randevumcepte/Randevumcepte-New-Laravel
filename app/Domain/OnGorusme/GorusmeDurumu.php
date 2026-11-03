<?php

namespace App\Domain\OnGorusme;

/**
 * Ön görüşmenin sonucu (on_gorusmeler.durum) — web, Flutter ve rapor için TEK sözlük.
 * DB değeri: NULL = beklemede, 1 = satış yapıldı, 0 = satış yapılmadı.
 *
 * Geçiş kuralı: yalnızca BEKLEMEDE bir görüşme sonuçlandırılabilir / düzenlenebilir
 * (eski ekran da işlem menüsünü yalnız beklemede satırda gösteriyordu).
 */
enum GorusmeDurumu: string
{
    case BEKLEMEDE = 'beklemede';
    case SATIS_YAPILDI = 'satis_yapildi';
    case SATIS_YAPILMADI = 'satis_yapilmadi';

    /** DB değerinden (null|''|0|1, string de olabilir) durum. Tanınmayan → beklemede. */
    public static function dbden(mixed $durum): self
    {
        if ($durum === null || $durum === '') {
            return self::BEKLEMEDE;
        }

        return match ((int) $durum) {
            1 => self::SATIS_YAPILDI,
            0 => self::SATIS_YAPILMADI,
            default => self::BEKLEMEDE,
        };
    }

    /** İstek / filtre kodundan ('beklemede' | 'satis_yapildi' | 'satis_yapilmadi'); geçersizse null. */
    public static function koddan(?string $kod): ?self
    {
        return $kod === null ? null : self::tryFrom($kod);
    }

    /** DB'ye yazılacak durum değeri. */
    public function db(): ?int
    {
        return match ($this) {
            self::BEKLEMEDE => null,
            self::SATIS_YAPILDI => 1,
            self::SATIS_YAPILMADI => 0,
        };
    }

    public function etiket(): string
    {
        return match ($this) {
            self::BEKLEMEDE => 'Beklemede',
            self::SATIS_YAPILDI => 'Satış Yapıldı',
            self::SATIS_YAPILMADI => 'Satış Yapılmadı',
        };
    }

    /** Düzenleme ve sonuçlandırma yalnızca beklemedeyken. */
    public function acikMi(): bool
    {
        return $this === self::BEKLEMEDE;
    }

    /** Sekme / filtre seçenekleri [{kod, ad}] — istemciler etiketi kopyalamaz. */
    public static function secenekler(): array
    {
        return array_map(fn (self $d) => ['kod' => $d->value, 'ad' => $d->etiket()], self::cases());
    }
}
