<?php

namespace App\Domain\BildirimReklam;

/**
 * Bildirim reklamının türü (bildirim_reklamlari.tur) — web, Flutter ve rapor için TEK sözlük.
 * Etiket / ikon / renk eski ekranın $turEtiket tablosuyla birebir (ikonlar bootstrap-icons).
 * Tanınmayan / boş değer → kampanya (eski kaydet: `$request->tur ?: 'kampanya'`).
 */
enum ReklamTuru: string
{
    case KAMPANYA = 'kampanya';
    case BOS_SLOT = 'bos_slot';
    case YENI_HIZMET = 'yeni_hizmet';
    case GERI_KAZANIM = 'geri_kazanim';
    case OZEL_GUN = 'ozel_gun';
    case SADAKAT = 'sadakat';
    case ETKINLIK = 'etkinlik';

    public static function dbden(?string $tur): self
    {
        return self::tryFrom((string) $tur) ?? self::KAMPANYA;
    }

    public function etiket(): string
    {
        return match ($this) {
            self::KAMPANYA => 'Kampanya / İndirim',
            self::BOS_SLOT => 'Boş Slot Doldurma',
            self::YENI_HIZMET => 'Yeni Hizmet Tanıtımı',
            self::GERI_KAZANIM => 'Yeniden Kazanım',
            self::OZEL_GUN => 'Özel Gün',
            self::SADAKAT => 'Sadakat / Puan',
            self::ETKINLIK => 'Etkinlik / Duyuru',
        };
    }

    public function ikon(): string
    {
        return match ($this) {
            self::KAMPANYA => 'bi-tags',
            self::BOS_SLOT => 'bi-clock',
            self::YENI_HIZMET => 'bi-star',
            self::GERI_KAZANIM => 'bi-heart',
            self::OZEL_GUN => 'bi-gift',
            self::SADAKAT => 'bi-gem',
            self::ETKINLIK => 'bi-megaphone',
        };
    }

    public function renk(): string
    {
        return match ($this) {
            self::KAMPANYA => '#7c3aed',
            self::BOS_SLOT => '#0ea5e9',
            self::YENI_HIZMET => '#f59e0b',
            self::GERI_KAZANIM => '#ef4444',
            self::OZEL_GUN => '#ec4899',
            self::SADAKAT => '#10b981',
            self::ETKINLIK => '#6366f1',
        };
    }

    /** @return list<array{kod:string, ad:string, ikon:string, renk:string}> */
    public static function secenekler(): array
    {
        return array_map(fn (self $t) => ['kod' => $t->value, 'ad' => $t->etiket(), 'ikon' => $t->ikon(), 'renk' => $t->renk()], self::cases());
    }

    /** @return list<string> */
    public static function kodlar(): array
    {
        return array_column(self::cases(), 'value');
    }
}
