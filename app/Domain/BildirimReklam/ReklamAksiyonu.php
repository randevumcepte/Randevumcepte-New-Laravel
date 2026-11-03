<?php

namespace App\Domain\BildirimReklam;

/**
 * Müşteri görsele dokununca ne olur (bildirim_reklamlari.aksiyon_tipi).
 *   kupon   : tek dokunuşla indirim kuponu tanımlanır (değer ZORUNLU)
 *   randevu : boş slot — tarih/saat penceresiyle randevu ekranı; kupon OPSİYONEL
 *   yok     : sadece görsel
 */
enum ReklamAksiyonu: string
{
    case KUPON = 'kupon';
    case RANDEVU = 'randevu';
    case YOK = 'yok';

    public static function dbden(?string $aksiyon): self
    {
        return self::tryFrom((string) $aksiyon) ?? self::KUPON;
    }

    public function etiket(): string
    {
        return match ($this) {
            self::KUPON => 'İndirim kuponu tanımlansın (tek dokunuş)',
            self::RANDEVU => 'Boş slot: indirimli saatte randevu (kupon + randevu)',
            self::YOK => 'Sadece görsel (aksiyon yok)',
        };
    }

    /** Kupon ayarları bu aksiyonda tutulur mu (randevuda opsiyonel kupon). */
    public function kuponAlaniVar(): bool
    {
        return $this !== self::YOK;
    }

    /** Kupon değeri girilmesi zorunlu mu. */
    public function kuponZorunlu(): bool
    {
        return $this === self::KUPON;
    }

    /** Randevu (boş slot) tarih/saat penceresi tutulur mu. */
    public function randevuPenceresiVar(): bool
    {
        return $this === self::RANDEVU;
    }

    /** @return list<array{kod:string, ad:string, kupon:bool, kupon_zorunlu:bool, randevu:bool}> */
    public static function secenekler(): array
    {
        return array_map(fn (self $a) => [
            'kod' => $a->value, 'ad' => $a->etiket(),
            'kupon' => $a->kuponAlaniVar(), 'kupon_zorunlu' => $a->kuponZorunlu(), 'randevu' => $a->randevuPenceresiVar(),
        ], self::cases());
    }

    /** @return list<string> */
    public static function kodlar(): array
    {
        return array_column(self::cases(), 'value');
    }
}
