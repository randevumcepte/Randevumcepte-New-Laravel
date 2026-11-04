<?php

namespace App\Domain\Ders;

/**
 * Grup dersi katilimcisinin durumu (ders_katilimcilar.durum) — web, Flutter ve rapor
 * icin TEK sozluk. Kontenjan kurali da burada: iptal ve bekleme kontenjan TUTMAZ.
 */
enum KatilimciDurumu: string
{
    case REZERVE = 'rezerve';
    case GELDI = 'geldi';
    case GELMEDI = 'gelmedi';
    case TELAFI = 'telafi';
    case BEKLEME = 'bekleme';
    case IPTAL = 'iptal';

    /** Kontenjan sayiminda haric tutulan durumlar (sorgularda whereNotIn). */
    public const KONTENJAN_DISI = ['iptal', 'bekleme'];

    public function kontenjanTutar(): bool
    {
        return ! in_array($this->value, self::KONTENJAN_DISI, true);
    }

    public function etiket(): string
    {
        return match ($this) {
            self::REZERVE => 'Rezerve',
            self::GELDI => 'Geldi',
            self::GELMEDI => 'Gelmedi',
            self::TELAFI => 'Telafi',
            self::BEKLEME => 'Beklemede',
            self::IPTAL => 'İptal',
        };
    }

    /** Isletmenin elle isaretleyebildigi durumlar (bekleme/iptal yalniz akisla olusur). */
    public static function isaretlenebilir(): array
    {
        return [self::REZERVE, self::GELDI, self::GELMEDI, self::TELAFI];
    }

    /** Yeni kayit kapasiteye gore rezerve mi bekleme mi? */
    public static function yeniKayit(int $doluluk, int $kapasite): self
    {
        return $doluluk >= $kapasite ? self::BEKLEME : self::REZERVE;
    }

    /** Istemcilerin buton/rozet metinleri icin sozluk (UI'da sabit liste tutulmaz). */
    public static function sozluk(): array
    {
        return array_map(fn (self $d) => ['kod' => $d->value, 'etiket' => $d->etiket(), 'isaretlenebilir' => in_array($d, self::isaretlenebilir(), true)], self::cases());
    }
}
