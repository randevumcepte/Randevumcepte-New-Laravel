<?php

namespace App\Domain\BildirimReklam;

/**
 * Reklamın yayın durumu (bildirim_reklamlari.durum). Eski ekranla aynı dört değer;
 * 'bitti' yalnız durum ucundan verilebilir (formda seçilmez — eski select'te yoktu).
 * Tanınmayan değer: formda taslak, durum ucunda pasif (eski davranışlar).
 */
enum ReklamDurumu: string
{
    case TASLAK = 'taslak';
    case AKTIF = 'aktif';
    case PASIF = 'pasif';
    case BITTI = 'bitti';

    public static function dbden(?string $durum): self
    {
        return self::tryFrom((string) $durum) ?? self::TASLAK;
    }

    public function etiket(): string
    {
        return match ($this) {
            self::TASLAK => 'Taslak',
            self::AKTIF => 'Aktif',
            self::PASIF => 'Pasif',
            self::BITTI => 'Bitti',
        };
    }

    public function renk(): string
    {
        return match ($this) {
            self::TASLAK => '#64748b',
            self::AKTIF => '#16a34a',
            self::PASIF => '#94a3b8',
            self::BITTI => '#dc2626',
        };
    }

    /** Formda seçilebilen durumlar (eski select: taslak / aktif / pasif). */
    public function formdaSecilir(): bool
    {
        return $this !== self::BITTI;
    }

    /** @return list<array{kod:string, ad:string, renk:string, formda:bool}> */
    public static function secenekler(): array
    {
        return array_map(fn (self $d) => ['kod' => $d->value, 'ad' => $d->etiket(), 'renk' => $d->renk(), 'formda' => $d->formdaSecilir()], self::cases());
    }

    /** @return list<string> */
    public static function kodlar(): array
    {
        return array_column(self::cases(), 'value');
    }
}
