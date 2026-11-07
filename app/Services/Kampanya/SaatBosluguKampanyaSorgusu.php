<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\MusteriKampanyasi;
use App\Domain\Kampanya\SaatBosluguKampanyasi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Salonun süresi dolmamış `kampanyalar` satırları: takvim şeridi, tahsilat bandı, müşteri ana sayfası. */
class SaatBosluguKampanyaSorgusu
{
    private const MUSTERI_LIMITI = 50;

    /** @return list<array<string, mixed>> Sabah, öğleden sonra, akşam sırasıyla */
    public function aktifler(int $salonId): array
    {
        $simdi = now();

        return $this->gecerli($salonId)
            ->where('kampanya_detay', 'like', '%'.SaatBosluguKampanyasi::ETIKET_ONEKI.'%')
            ->orderBy('id')
            ->get(['id', 'kampanya_baslik', 'kampanya_aciklama', 'kampanya_detay', 'kampanya_bitis_tarihi'])
            ->map(fn ($k) => SaatBosluguKampanyasi::satirdan($k, $simdi))
            ->filter()
            ->sortBy('order')
            ->values()
            ->all();
    }

    /** Verilen saat (yoksa şimdiki saat) için aktif kampanya var mı. Eski randevuKampanyaKontrol. */
    public function kontrol(int $salonId, ?string $saat): array
    {
        $simdi = now();
        $s = $saat ? (int) substr($saat, 0, 2) : (int) $simdi->format('H');
        $anahtar = SaatBosluguKampanyasi::saattenAnahtar($s);
        $temel = ['gapKey' => $anahtar, 'gapLabel' => SaatBosluguKampanyasi::ad($anahtar), 'hour' => $s];

        $k = $this->gecerli($salonId)
            ->where('kampanya_detay', 'like', '%'.SaatBosluguKampanyasi::etiket($anahtar).'%')
            ->orderByDesc('id')
            ->first(['id', 'kampanya_baslik', 'kampanya_aciklama', 'kampanya_bitis_tarihi']);
        if (! $k) {
            return ['hasCampaign' => false] + $temel;
        }

        return ['hasCampaign' => true, 'kampanyaId' => (int) $k->id] + $temel + [
            'discount'    => SaatBosluguKampanyasi::indirim((string) $k->kampanya_aciklama, (string) $k->kampanya_baslik),
            'baslik'      => $k->kampanya_baslik,
            'aciklama'    => $k->kampanya_aciklama,
            'bitisTarihi' => $k->kampanya_bitis_tarihi,
            'kalanGun'    => SaatBosluguKampanyasi::kalanGun((string) $k->kampanya_bitis_tarihi, $simdi),
        ];
    }

    /** @return list<array<string, mixed>> Müşteriye açık alanlar (yeniden eskiye, en çok 50) */
    public function musteriye(int $salonId): array
    {
        $simdi = now();

        return $this->gecerli($salonId)
            ->orderByDesc('id')
            ->limit(self::MUSTERI_LIMITI)
            ->get(['id', 'kampanya_baslik', 'kampanya_aciklama', 'kampanya_fiyat', 'hizmet_normal_fiyat', 'kampanya_detay', 'kampanya_bitis_tarihi'])
            ->map(fn ($k) => MusteriKampanyasi::satirdan($k, $simdi))
            ->values()
            ->all();
    }

    private function gecerli(int $salonId): Builder
    {
        return DB::table('kampanyalar')
            ->where('salon_id', $salonId)
            ->where('onayli', 1)
            ->where('kampanya_bitis_tarihi', '>=', now()->toDateTimeString());
    }
}
