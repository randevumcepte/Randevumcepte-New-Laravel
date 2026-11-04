<?php

namespace App\Services\Hesabim\Hizmet;

use App\Domain\Hesabim\HizmetKarti;
use App\Domain\Hesabim\UyelikKatalogu;
use Illuminate\Support\Facades\DB;

/** Çarkıfelek kartı (Premium) — çark kuruluysa gerçek aktiflik + dilim sayısıyla. */
class CarkKaynagi implements HizmetKaynagi
{
    public function kartlar(object $salon): array
    {
        if (! UyelikKatalogu::premiumMu($salon->uyelik_turu ?? null)) {
            return [];
        }
        $cark = DB::table('carkifelek_sistemi')->where('salon_id', $salon->id)->first(['id', 'aktifmi']);
        if (! $cark) {
            return [];
        }

        $aktif = (int) $cark->aktifmi === 1;
        $dilim = DB::table('carkifelek_dilimleri')->where('cark_id', $cark->id)->count();
        $aciklama = ! $aktif
            ? 'Çark kurulu fakat pasif'
            : ($dilim > 0 ? $dilim.' dilim tanımlı, çark dönüyor' : 'Aktif — henüz dilim eklenmemiş');

        return [HizmetKarti::pakete('cark', 'Çarkıfelek', $aciklama, 'fa-trophy', 'bi-trophy', 'turuncu', $aktif)];
    }
}
