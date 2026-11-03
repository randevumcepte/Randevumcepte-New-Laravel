<?php

namespace App\Domain\CagriMerkezi;

/**
 * Arama listesi durumu (arama_listesi.durum) — eski cagri_liste_durum ile aynı kodlar.
 *   1 aktif · 3 pasif (görünür ama hatırlatma/bildirim üretmez) · 2 arşiv (çalışma ekranından gizli, veri silinmez)
 * 0 / NULL (eski kayıtlar) aktif kabul edilir.
 */
enum ListeDurumu: int
{
    case AKTIF = 1;
    case ARSIV = 2;
    case PASIF = 3;

    public static function dbden(int|string|null $v): self
    {
        return self::tryFrom((int) $v) ?? self::AKTIF;
    }

    public function etiket(): string
    {
        return match ($this) {
            self::AKTIF => 'Aktif',
            self::ARSIV => 'Arşiv',
            self::PASIF => 'Pasif',
        };
    }

    /** Durum değişikliği mesajı (eski: '"X" listesi aktif yapıldı.'). */
    public function gecisMetni(): string
    {
        return match ($this) {
            self::AKTIF => 'aktif yapıldı',
            self::ARSIV => 'arşive alındı',
            self::PASIF => 'pasife alındı',
        };
    }

    /** Hatırlatma / popup üretir mi? (arşiv + pasif üretmez) */
    public function bildirimUretir(): bool
    {
        return $this === self::AKTIF;
    }

    public static function secenekler(): array
    {
        return array_map(fn (self $d) => ['kod' => $d->value, 'ad' => $d->etiket()], self::cases());
    }
}
