<?php

namespace App\Services\Rapor;

use App\Domain\Rapor\RaporDonemi;
use App\Services\Yetki\KapsamServisi;
use App\Services\Yetki\YetkiServisi;
use Illuminate\Support\Facades\DB;

/**
 * Rapor ekranlarinin ACILIS verisi — donem secenekleri, kullanicinin gorebilecegi
 * sekmeler, buton yetkileri, personel kapsami. Web ve Flutter sekme/buton gorunurlugunu
 * buradan alir (UI'da sabit liste / yetki kurali yok). Kapsam denetimi controller'da.
 */
class RaporSecenekServisi
{
    public const EKRANLAR = ['satis', 'isletme', 'kasa'];

    /** Kasa ekranina giris (eski kasadefteri sayfa kapisi: herhangi biri). */
    public const KASA_GIRIS = ['rapor.kasa', 'finans.kasa_giris_cikis', 'finans.masraf_gor', 'finans.masraf_ekle', 'finans.alacak_yonet'];

    public function __construct(private YetkiServisi $yetki, private KapsamServisi $kapsam) {}

    public function getir(int $yetkiliId, int $salonId, string $ekran): array
    {
        $var = fn (string $a) => $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, $a);

        return match ($ekran) {
            'satis' => $this->satis($yetkiliId, $salonId, $var),
            'isletme' => [
                'donemler' => RaporDonemi::secenekListesi(),
                'varsayilan_donem' => 'bugun',
                'sekmeler' => array_values(array_filter([
                    ['kod' => 'isletme', 'ad' => 'İşletme', 'ikon' => 'bi-building', 'yetki' => $var('rapor.satis')],
                    ['kod' => 'hizmet', 'ad' => 'Hizmet', 'ikon' => 'bi-clipboard2-check', 'yetki' => $var('rapor.satis')],
                    ['kod' => 'urun', 'ad' => 'Ürün', 'ikon' => 'bi-box-seam', 'yetki' => $var('rapor.satis')],
                    ['kod' => 'personel', 'ad' => 'Personel', 'ikon' => 'bi-people', 'yetki' => $var('rapor.personel_performans')],
                    ['kod' => 'musteri', 'ad' => 'Müşteri', 'ikon' => 'bi-person-heart', 'yetki' => $var('rapor.musteri')],
                    ['kod' => 'randevu', 'ad' => 'Randevu', 'ikon' => 'bi-calendar-check', 'yetki' => $var('rapor.satis')],
                ], fn ($s) => $s['yetki'])),
            ],
            'kasa' => [
                'donemler' => RaporDonemi::secenekListesi(),
                'varsayilan_donem' => 'ay',
                'odeme_yontemleri' => DB::table('odeme_yontemleri')->orderBy('id')->get(['id', 'odeme_yontemi'])->all(),
                'yetkiler' => [
                    'kasa_gor'         => $var('rapor.kasa'),
                    'gun_sonu'         => $var('rapor.kasa'),
                    'masraf_gor'       => $var('finans.masraf_gor'),
                    'masraf_ekle'      => $var('finans.masraf_ekle'),
                    'kasa_giris_cikis' => $var('finans.kasa_giris_cikis'),
                    'tahsilat_sil'     => $var('satis.tahsilat_sil'),
                ],
            ],
        };
    }

    /**
     * Personel (rol 5) hesabi raporu yalniz KENDI satislariyla gorur (mobil
     * SalesReportsPersonelPage ile ayni) — zorunlu salon_personelleri.id; yonetici icin null.
     */
    public function zorunluPersonel(int $yetkiliId, int $salonId): ?int
    {
        if (! in_array(5, $this->kapsam->salonRolleri($yetkiliId, $salonId), true)) {
            return null;
        }
        return $this->bagliPersonel($yetkiliId, $salonId);
    }

    /** Yetkilinin bu salondaki personel kaydi; yoksa -1 (hicbir kayit eslesmez). */
    public function bagliPersonel(int $yetkiliId, int $salonId): int
    {
        $id = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)->value('id');

        return $id ? (int) $id : -1;
    }

    private function satis(int $yetkiliId, int $salonId, callable $var): array
    {
        $zorunlu = $this->zorunluPersonel($yetkiliId, $salonId);
        $sekmeler = [
            ['kod' => 'tum', 'ad' => 'Tümü', 'ikon' => 'bi-layers'],
            ['kod' => 'hizmet', 'ad' => 'Hizmet Raporları', 'ikon' => 'bi-clipboard2-check'],
            ['kod' => 'urun', 'ad' => 'Ürün Raporları', 'ikon' => 'bi-box-seam'],
            ['kod' => 'paket', 'ad' => 'Paket Raporları', 'ikon' => 'bi-gift'],
        ];
        if ($zorunlu === null) {
            $sekmeler[] = ['kod' => 'personel', 'ad' => 'Personel Raporları', 'ikon' => 'bi-person'];
        }

        return [
            'donemler'          => RaporDonemi::secenekListesi(),
            'varsayilan_donem'  => 'ay',
            'sekmeler'          => $sekmeler,
            'personel_kapsami'  => $zorunlu,
            'personel_filtresi' => $zorunlu === null,
            'maskeli'           => ! $var('rapor.ciro_kar_gor'),
        ];
    }
}
