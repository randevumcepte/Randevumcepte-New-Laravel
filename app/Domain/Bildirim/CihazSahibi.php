<?php

namespace App\Domain\Bildirim;

/**
 * bildirim_kimlikleri satırının sahibi (saf değer nesnesi). Şema tarihseldir: müşteri `user_id`,
 * personel/yetkili `isletme_yetkili_id` (= salon_personelleri.id) kolonunda tutulur; bu ayrımı
 * bilen TEK yer burasıdır.
 */
final class CihazSahibi
{
    private const MUSTERI_KOLONU = 'user_id';
    private const PERSONEL_KOLONU = 'isletme_yetkili_id';

    private function __construct(
        public readonly string $kullaniciTipi,
        public readonly string $kolon,
        public readonly int $id,
        public readonly ?int $salonId,
    ) {}

    public static function musteri(int $userId, ?int $salonId): self
    {
        return new self('musteri', self::MUSTERI_KOLONU, $userId, $salonId);
    }

    /** @param bool $yetkili true: hesap sahibi/yönetici; false: personel rolü */
    public static function personel(int $personelId, int $salonId, bool $yetkili): self
    {
        return new self($yetkili ? 'yetkili' : 'personel', self::PERSONEL_KOLONU, $personelId, $salonId);
    }

    /** @return array{user_id:?int, isletme_yetkili_id:?int} Aynı cihaz başka rolle girince eski rol satırda kalmaz. */
    public function sahipKolonlari(): array
    {
        return [self::MUSTERI_KOLONU => null, self::PERSONEL_KOLONU => null, $this->kolon => $this->id];
    }
}
