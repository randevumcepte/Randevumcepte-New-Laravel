<?php

namespace App\Services\Yetki;

use App\Exceptions\GecersizIslemException;
use Illuminate\Support\Facades\DB;

/**
 * Govdede gelen ORTAK referanslarin (musteri / personel / hizmet) istekteki salona
 * ait oldugunun denetimi — tek kaynak. Domain'e ozel kaynaklar (urun, depo, paket…)
 * kendi kapsam siniflarinda kalir; bu uc referans birden fazla domainde (stok hizli
 * satis, recete, paket tanimi / satisi) kullanildigi icin burada.
 *
 * Ihlal: GecersizIslemException (422) — baska isletmenin kaydina sizma yok.
 */
class SalonSahipligi
{
    /** Musteri bu salonun portfoyunde mi. */
    public function musteriOlmali(int $userId, int $salonId): void
    {
        if (! DB::table('musteri_portfoy')->where('user_id', $userId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Müşteri bu işletmeye ait değil');
        }
    }

    /** Personel (verildiyse) bu salonun personeli mi. */
    public function personelOlmali(?int $personelId, int $salonId): void
    {
        if (! $personelId) {
            return;
        }
        if (! DB::table('salon_personelleri')->where('id', $personelId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Personel bu işletmeye ait değil');
        }
    }

    /**
     * Hizmet(ler) bu salonun sundugu hizmetlerden mi (salon_sunulan_hizmetler).
     *
     * @param  int|array<int>  $hizmetIdleri
     */
    public function hizmetOlmali(int|array $hizmetIdleri, int $salonId): void
    {
        $idler = array_values(array_unique(array_filter(array_map('intval', (array) $hizmetIdleri))));
        if (! $idler) {
            return;
        }
        $bulunan = DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)
            ->whereIn('hizmet_id', $idler)->distinct()->count('hizmet_id');
        if ($bulunan !== count($idler)) {
            throw new GecersizIslemException(count($idler) > 1 ? 'Seçilen hizmetlerden biri bu işletmede tanımlı değil' : 'Hizmet bu işletmede tanımlı değil');
        }
    }
}
