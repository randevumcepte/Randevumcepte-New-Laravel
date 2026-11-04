<?php

namespace App\Services\OnGorusme;

use App\Domain\Musteri\MusteriSozluk;
use App\Domain\OnGorusme\GorusmeDurumu;
use App\Models\Salonlar;
use App\Services\Randevu\RandevuFormServisi;
use App\Services\Satis\KatalogServisi;
use Illuminate\Support\Facades\DB;

/**
 * Ön görüşme ekranı / formu seçenekleri — web ve Flutter TEK kaynaktan okur (UI'da sabit liste yok).
 * Kaynaklar yeniden kullanılır: neden kataloğu KatalogServisi, personel / oda / müşteri etiketi
 * RandevuFormServisi::kaynakSecenekleri.
 */
class OnGorusmeFormServisi
{
    public function __construct(
        private KatalogServisi $katalog,
        private RandevuFormServisi $randevuForm,
    ) {}

    /**
     * @param  array<string,bool>  $yetkiler  controller'da çözülmüş (ekle_duzenle, fiyat_degistir, telefon_gor)
     */
    public function formVerisi(int $salonId, ?int $kendiPersonelId, array $yetkiler): array
    {
        $k = $this->randevuForm->kaynakSecenekleri($salonId);
        $katalog = $this->katalog->listele($salonId);
        // fiyat / seans = katalog değeri (satış modalı ön-doldurma; ürün fiyatı BİRİM). Seans yalnız pakette.
        $secenek = fn (string $tur, string $idAlan) => fn (array $x) => [
            'tur' => $tur, 'id' => (int) $x[$idAlan], 'anahtar' => $tur.'-'.$x[$idAlan], 'ad' => $x['ad'],
            'fiyat' => $x['fiyat'] !== null ? (float) $x['fiyat'] : null, 'seans' => isset($x['seans']) ? (int) $x['seans'] : null,
        ];

        return [
            'musteri_etiket' => $k['musteri_etiket'],
            // Tek select'te üç tür (eski "Ön Görüşme Sebebi"): anahtar = "tur-id".
            'nedenler' => [
                ['tur' => 'paket', 'etiket' => 'Paketler', 'secenekler' => array_map($secenek('paket', 'paket_id'), $katalog['paketler'])],
                ['tur' => 'urun', 'etiket' => 'Ürünler', 'secenekler' => array_map($secenek('urun', 'urun_id'), $katalog['urunler'])],
                ['tur' => 'hizmet', 'etiket' => 'Hizmetler', 'secenekler' => array_map($secenek('hizmet', 'hizmet_id'), $katalog['hizmetler'])],
            ],
            'personeller'            => $k['personeller'],
            'varsayilan_personel_id' => $kendiPersonelId ?? $this->hesapSahibiPersonel($salonId),
            // Takvim odaya göreyse (tür 3) oda seçimi zorunlu (eski modal).
            'oda_zorunlu'  => $k['takvim_turu'] === 3,
            'odalar'       => $k['takvim_turu'] === 3 ? $k['odalar'] : [],
            'referanslar'  => MusteriSozluk::secenekler(MusteriSozluk::REFERANSLAR),
            'cinsiyetler'  => MusteriSozluk::secenekler(MusteriSozluk::CINSIYETLER),
            'durumlar'     => GorusmeDurumu::secenekler(),
            'saat_araligi' => config('ongorusme.saat_araligi'),
            'sure_dk'      => (int) config('ongorusme.sure_dk', 60),
            'studyo_modu'  => Salonlar::studyoModu($salonId), // fiyat gizleme (MODEL B)
            'yetkiler'     => $yetkiler,
            'yazma_aktif'  => (bool) config('ongorusme.yazma_aktif'),
        ];
    }

    /** Oturum sahibinin bu salonda personel kaydı yoksa hesap sahibi (eski modal fallback'i). */
    private function hesapSahibiPersonel(int $salonId): ?int
    {
        $id = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('role_id', 1)->value('id');

        return $id ? (int) $id : null;
    }
}
