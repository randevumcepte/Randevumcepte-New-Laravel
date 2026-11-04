<?php

namespace App\Services\Bildirim;

use Illuminate\Database\Query\Builder;

/**
 * Müşteri uygulaması kutusu. $salonlar null → tüm salonlar (ana uygulama). $salonsuzDahil: marka
 * uygulamasında salon_id'si olmayan eski kayıtlar da kutuda kalır (eski bildirimgetirmusteri kuralı).
 */
final class MusteriKutusu implements BildirimKutusu
{
    /** @param int[]|null $salonlar */
    public function __construct(
        private readonly int $userId,
        private readonly ?array $salonlar,
        private readonly bool $salonsuzDahil,
        private readonly ?int $salon,
    ) {}

    public function kapsamla(Builder $q): void
    {
        $q->where('user_id', $this->userId);
        if ($this->salonlar === null) {
            return;
        }
        $q->where(function (Builder $w) {
            $w->whereIn('salon_id', $this->salonlar);
            if ($this->salonsuzDahil) {
                $w->orWhereNull('salon_id');
            }
        });
    }

    public function sun(object $satir, ?string $arsivUzanti, ?int $randevuMusterisi): array
    {
        return BildirimSunumu::satir($satir, $arsivUzanti);
    }

    public function salonId(): ?int
    {
        return $this->salon;
    }

    public function yetkiliId(): ?int
    {
        return null;
    }
}
