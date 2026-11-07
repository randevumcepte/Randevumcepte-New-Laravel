<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\SaatBosluguKampanyasi;
use Illuminate\Support\Facades\DB;

/** Salonun süresi dolmamış saat boşluğu kampanyaları; takvim şeridi okur. */
class SaatBosluguKampanyaSorgusu
{
    /** @return list<array<string, mixed>> Sabah, öğleden sonra, akşam sırasıyla */
    public function aktifler(int $salonId): array
    {
        $simdi = now();
        $satirlar = DB::table('kampanyalar')
            ->where('salon_id', $salonId)
            ->where('onayli', 1)
            ->where('kampanya_bitis_tarihi', '>=', $simdi->toDateTimeString())
            ->where('kampanya_detay', 'like', '%[gap:%')
            ->orderBy('id')
            ->get(['id', 'kampanya_baslik', 'kampanya_aciklama', 'kampanya_detay', 'kampanya_bitis_tarihi']);

        return $satirlar
            ->map(fn ($k) => SaatBosluguKampanyasi::satirdan($k, $simdi))
            ->filter()
            ->sortBy('order')
            ->values()
            ->all();
    }
}
