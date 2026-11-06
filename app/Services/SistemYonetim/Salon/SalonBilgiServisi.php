<?php

namespace App\Services\SistemYonetim\Salon;

use App\Services\SistemYonetim\Denetim;
use Illuminate\Support\Facades\DB;

/** Salonun temel isletme bilgileri. Yalniz gelen alanlar yazilir; bos metin NULL olur. */
class SalonBilgiServisi
{
    public function guncelle(int $salonId, array $alanlar): void
    {
        $eski = (array) DB::table('salonlar')->where('id', $salonId)->first(array_keys($alanlar) ?: ['id']);
        $yeni = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v), $alanlar);
        if (array_key_exists('adres', $yeni)) {
            $yeni['adres'] ??= ''; // kolon NOT NULL
        }
        $degisen = array_keys(array_filter($yeni, fn ($v, $k) => (string) $v !== (string) ($eski[$k] ?? ''), ARRAY_FILTER_USE_BOTH));

        if ($degisen) {
            DB::table('salonlar')->where('id', $salonId)->update(array_intersect_key($yeni, array_flip($degisen)) + ['updated_at' => now()]);
        }
        Denetim::yaz('salon_bilgi_guncelle', 'salon', $salonId, DB::table('salonlar')->where('id', $salonId)->value('salon_adi'), 'Temel işletme bilgileri güncellendi', ['degisen' => $degisen]);
    }
}
