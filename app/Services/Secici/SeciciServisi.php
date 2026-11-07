<?php

namespace App\Services\Secici;

use Illuminate\Support\Facades\DB;

/**
 * Formlarin ortak secim listeleri (randevu, satis, on gorusme; web + mobil). Salonun her calisani
 * gorur, ozel yetki istemez; bu yuzden yalniz secim icin gereken alanlar doner.
 */
class SeciciServisi
{
    private const SALON_ALANLARI = [
        'id', 'salon_adi', 'randevu_takvim_turu', 'randevu_saat_araligi', 'cakisma_uyarisi_aktif', 'gecmis_randevulari_gizle',
        'musteri_online_randevu_aktif', 'aktif_musteri_indirim_yuzde', 'sadik_musteri_indirim_yuzde',
    ];

    public function hizmetler(int $salonId): array
    {
        $satirlar = DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('aktif', 1)
            ->get(['hizmet_id', 'hizmet_kategori_id', 'sure_dk', 'baslangic_fiyat', 'bolum']);
        $hizmet = DB::table('hizmetler')->whereIn('id', $satirlar->pluck('hizmet_id')->filter()->unique())
            ->get(['id', 'hizmet_adi', 'hizmet_kategori_id'])->keyBy('id');
        $kategori = DB::table('hizmet_kategorisi')->whereIn('id', $satirlar->pluck('hizmet_kategori_id')->filter()->unique())
            ->get(['id', 'hizmet_kategorisi_adi'])->keyBy('id');

        return $satirlar->map(fn ($s) => (array) $s + [
            'hizmetler'         => $hizmet[$s->hizmet_id] ?? null,
            'hizmet_kategorisi' => $kategori[$s->hizmet_kategori_id] ?? null,
        ])->all();
    }

    public function cihazlar(int $salonId): array
    {
        return $this->kaynak('cihazlar', 'cihaz_adi', $salonId);
    }

    public function odalar(int $salonId): array
    {
        return $this->kaynak('odalar', 'oda_adi', $salonId);
    }

    public function sehirler(): array
    {
        return DB::table('il')->orderBy('id')->get(['id', 'il_adi'])->all();
    }

    public function salon(int $salonId): array
    {
        return array_intersect_key((array) DB::table('salonlar')->where('id', $salonId)->first(), array_flip(self::SALON_ALANLARI));
    }

    private function kaynak(string $tablo, string $adKolon, int $salonId): array
    {
        return DB::table($tablo)->where('salon_id', $salonId)->where('aktifmi', 1)
            ->get(['id', $adKolon, 'durum', 'aciklama', 'aktifmi'])->all();
    }
}
