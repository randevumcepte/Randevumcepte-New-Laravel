<?php

namespace App\Services\Bildirim;

use Illuminate\Database\Query\Builder;

/**
 * Panel/yönetici kutusu: bu salondaki KENDİ personel kaydına yazılan bildirimler.
 *
 * Personel kaydı yoksa kutu boştur. Eski web ucu burada `personel_id IS NULL` sorgusuna düşüp
 * salonun müşteriye yazılmış bildirimlerini listeliyordu.
 */
final class PersonelKutusu implements BildirimKutusu
{
    public function __construct(
        private readonly int $salon,
        private readonly ?int $personelId,
        private readonly int $yetkili,
    ) {}

    public function kapsamla(Builder $q): void
    {
        if (! $this->personelId) {
            $q->whereRaw('1 = 0');

            return;
        }
        $q->where('personel_id', $this->personelId)->where('salon_id', $this->salon);
    }

    public function sun(object $satir, ?string $arsivUzanti, ?int $randevuMusterisi): array
    {
        return BildirimSunumu::panel($satir, $arsivUzanti, $randevuMusterisi, $this->salon);
    }

    public function salonId(): ?int
    {
        return $this->salon;
    }

    public function yetkiliId(): ?int
    {
        return $this->yetkili;
    }
}
