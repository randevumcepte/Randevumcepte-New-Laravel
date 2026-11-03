<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\HedefKitle;
use App\Domain\Kampanya\Indirim;
use App\Domain\Kampanya\KatilimDurumu;
use App\Domain\Kampanya\Kanal;
use App\Domain\Kampanya\MesajMetni;
use App\Domain\Kampanya\Senaryo;
use App\Services\Randevu\RandevuFormServisi;
use App\Services\Satis\KatalogServisi;

/**
 * Reklam Yönetimi FORM VERİSİ — sihirbazın ve raporun TÜM seçenekleri tek uçtan (web + Flutter).
 * Eski v1 kampanyaFormVerileri (hizmetler/urunler/paketler/sablonlar/gruplar {value,label}) alanları
 * korunur; kanal / hedef / indirim / rapor sekmeleri / senaryo sözlükleri istemcide sabit DEĞİL.
 */
class KampanyaFormServisi
{
    /** Sihirbaz planlama varsayılanı: başlangıç bugün, bitiş +30 gün (eski form). */
    public const GECERLILIK_GUN = 30;

    public function __construct(
        private KatalogServisi $katalog,
        private KampanyaMesajServisi $mesaj,
        private HedefKitleServisi $kitle,
        private RandevuFormServisi $randevuForm,
    ) {}

    /** @param array<string,bool> $yetkiler */
    public function formVerisi(int $salonId, array $yetkiler): array
    {
        $k = $this->katalog->listele($salonId);
        $secenek = fn (array $liste, string $tur, string $idAlan) => array_map(
            fn ($x) => ['value' => $tur.'-'.$x[$idAlan], 'label' => $x['ad']], $liste);

        $kaynak = $this->randevuForm->kaynakSecenekleri($salonId);

        return [
            'musteri_etiket'    => $kaynak['musteri_etiket'],
            'personeller'       => $kaynak['personeller'],   // rapor: "Personele Ata"
            'kanallar'          => array_map(fn (Kanal $c) => $c->sozluk(), Kanal::cases()),
            'hedefler'          => HedefKitle::presetSozlugu(),
            'cinsiyetler'       => array_map(fn ($kod, $ad) => ['kod' => $kod, 'ad' => $ad], array_keys(HedefKitle::CINSIYETLER), HedefKitle::CINSIYETLER),
            'gruplar'           => $this->kitle->gruplar($salonId),
            'hizmetler'         => $secenek($k['hizmetler'], 'hizmet', 'hizmet_id'),
            'urunler'           => $secenek($k['urunler'], 'urun', 'urun_id'),
            'paketler'          => $secenek($k['paketler'], 'paket', 'paket_id'),
            'sablonlar'         => $this->mesaj->sablonlar($salonId),
            'yer_tutucular'     => MesajMetni::YER_TUTUCULAR,
            'indirim'           => [
                'turler'     => [['kod' => Indirim::YUZDE, 'ad' => 'Yüzde İndirim'], ['kod' => Indirim::XAL_YODE, 'ad' => 'X Al Y Öde']],
                'varsayilan' => Indirim::VARSAYILAN,
            ],
            'planlama'          => [
                'baslangic_tarihi' => now()->toDateString(),
                'bitis_tarihi'     => now()->addDays(self::GECERLILIK_GUN)->toDateString(),
                'gonderim_saati'   => now()->format('H:i'),
            ],
            'katilim_durumlari' => KatilimDurumu::sozluk(),
            'senaryo'           => [
                'adimlar'    => array_map(fn ($k, $ad) => ['kod' => $k, 'ad' => $ad], array_keys(Senaryo::ADIMLAR), Senaryo::ADIMLAR),
                'aksiyonlar' => array_map(fn ($k, $ad) => ['kod' => $k, 'ad' => $ad], array_keys(Senaryo::AKSIYONLAR), Senaryo::AKSIYONLAR),
                'presetler'  => Senaryo::presetler(),
            ],
            'yetkiler'          => $yetkiler,
        ];
    }
}
