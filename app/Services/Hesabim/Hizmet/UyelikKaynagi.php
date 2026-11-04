<?php

namespace App\Services\Hesabim\Hizmet;

use App\Domain\Hesabim\HizmetKarti;
use App\Domain\Hesabim\UyelikKatalogu;

/** Ana üyelik kartı (Randevu Yönetimi). */
class UyelikKaynagi implements HizmetKaynagi
{
    public function kartlar(object $salon): array
    {
        $paket = UyelikKatalogu::paketAdi($salon->uyelik_turu ?? null);

        return [new HizmetKarti(
            kod: 'uyelik',
            ad: 'Randevu Yönetimi',
            aciklama: $paket ? $paket.' Paket' : 'Üyeliğiniz aktif değil',
            icon: 'fa-calendar-check-o',
            ikon: 'bi-calendar-check',
            renk: 'mor',
            aktif: (int) ($salon->uyelik_turu ?? 0) > 0,
            periyot: UyelikKatalogu::periyotAdi($salon->uyelik_periyodu ?? null) ?? '-',
            bitis: $salon->uyelik_bitis_tarihi ?? null,
        )];
    }
}
