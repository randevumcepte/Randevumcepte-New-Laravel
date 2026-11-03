<?php

namespace App\Services\Pazarlama;

use App\Domain\Ozet\OzetHesap;
use App\Domain\Rapor\RaporDonemi;
use Illuminate\Support\Facades\DB;

/**
 * Memnuniyet anketi DONEM OZETI — eski StoreAdminController::dashboardAnket ile birebir
 * (donem = gonderim_zamani; NPS = %promoter − %detractor; CSAT ortalamasi; son 3 yorum).
 * Tek toplama sorgusu (eskide 7 ayri COUNT). Hicbir sey YAZMAZ.
 *
 * Not: mobil Anket Yonetimi ekrani (v1 anketOzet) created_at + ortalama NPS kullaniyor;
 * o ekran tasinirken bu servis genisletilir (ikinci formul yazilmaz).
 */
class AnketOzetServisi
{
    public function ozet(int $salonId, RaporDonemi $d): array
    {
        $taban = fn () => DB::table('anket_gonderimleri')
            ->where('salon_id', $salonId)
            ->whereBetween('gonderim_zamani', [$d->basSaat(), $d->bitSaat()]);

        $k = $taban()->selectRaw('
            COUNT(*) as gonderim,
            SUM(cevaplandi = 1) as cevap,
            SUM(cevaplandi = 1 AND nps_skoru >= 9) as promoter,
            SUM(cevaplandi = 1 AND nps_skoru BETWEEN 7 AND 8) as pasif,
            SUM(cevaplandi = 1 AND nps_skoru <= 6) as detractor,
            AVG(CASE WHEN cevaplandi = 1 THEN csat_skoru END) as csat')->first();

        $gonderim = (int) $k->gonderim;
        $cevap = (int) $k->cevap;
        [$p, $pa, $de] = [(int) $k->promoter, (int) $k->pasif, (int) $k->detractor];

        $yorumlar = $taban()->where('cevaplandi', 1)
            ->whereNotNull('genel_yorum')->where('genel_yorum', '!=', '')
            ->orderByDesc('cevap_zamani')->limit(3)
            ->get(['ad_soyad', 'genel_yorum', 'nps_skoru', 'csat_skoru', 'cevap_zamani'])
            ->map(fn ($y) => [
                // Veri minimizasyonu: yalniz ilk ad (eski ekran da ilk adi gosteriyordu).
                'ad'          => trim(explode(' ', trim((string) $y->ad_soyad))[0]) ?: 'Müşteri',
                'yorum'       => (string) $y->genel_yorum,
                'nps_skoru'   => $y->nps_skoru === null ? null : (int) $y->nps_skoru,
                'csat_skoru'  => $y->csat_skoru === null ? null : (float) $y->csat_skoru,
                'cevap_zamani' => $y->cevap_zamani,
            ])->all();

        return [
            'toplam_gonderim' => $gonderim,
            'toplam_cevap'    => $cevap,
            'cevap_orani'     => OzetHesap::oran($cevap, $gonderim),
            'nps'             => [
                'skor'      => OzetHesap::nps($p, $pa, $de),
                'promoter'  => $p,
                'pasif'     => $pa,
                'detractor' => $de,
                'toplam'    => $p + $pa + $de,
            ],
            'csat_ort'     => $k->csat === null ? null : round((float) $k->csat, 2),
            'son_yorumlar' => $yorumlar,
            'donem'        => $d->dizi(),
        ];
    }
}
