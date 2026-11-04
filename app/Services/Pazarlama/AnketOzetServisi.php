<?php

namespace App\Services\Pazarlama;

use App\Domain\Anket\ReputasyonEsikleri;
use App\Domain\Ozet\OzetHesap;
use App\Domain\Rapor\RaporDonemi;
use App\Services\Anket\AnketGonderimSorgusu;
use Illuminate\Support\Facades\DB;

/**
 * Memnuniyet anketi DONEM OZETI — tek toplama sorgusu, iki gorunum (hicbir sey YAZMAZ):
 *   ozet()    : Ozet (dashboard) karti — eski StoreAdminController::dashboardAnket ile birebir
 *   yonetim() : Memnuniyet Anketi ekrani (web + Flutter) — eski anketSonuclari + mobil v1 anketOzet
 *
 * Donem = gonderim_zamani; NPS = %promoter − %detractor; CSAT ortalamasi.
 */
class AnketOzetServisi
{
    private const TOPLAM_SQL = '
        COUNT(*) as gonderim,
        SUM(cevaplandi = 1) as cevap,
        SUM(cevaplandi = 1 AND nps_skoru >= 9) as promoter,
        SUM(cevaplandi = 1 AND nps_skoru BETWEEN 7 AND 8) as pasif,
        SUM(cevaplandi = 1 AND nps_skoru <= 6) as detractor,
        AVG(CASE WHEN cevaplandi = 1 THEN csat_skoru END) as csat';

    private const TREND_GUN = 7;

    private function taban(int $salonId, RaporDonemi $d)
    {
        return DB::table('anket_gonderimleri')
            ->where('salon_id', $salonId)
            ->whereBetween('gonderim_zamani', [$d->basSaat(), $d->bitSaat()]);
    }

    public function ozet(int $salonId, RaporDonemi $d): array
    {
        $k = $this->taban($salonId, $d)->selectRaw(self::TOPLAM_SQL)->first();

        $gonderim = (int) $k->gonderim;
        $cevap = (int) $k->cevap;
        [$p, $pa, $de] = [(int) $k->promoter, (int) $k->pasif, (int) $k->detractor];

        $yorumlar = $this->taban($salonId, $d)->where('cevaplandi', 1)
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

    /**
     * Ekran ozeti. Anahtarlar mobil v1 anketOzet ile AYNI (camelCase) — Flutter gecisinde model degismesin;
     * v2 ekleri (npsSkor, uyariTelefon, donem) ayni bicimde.
     *
     * @param  object  $salon  AnketAyarServisi::satir
     */
    public function yonetim(int $salonId, RaporDonemi $d, object $salon): array
    {
        $esik = ReputasyonEsikleri::satirdan($salon);
        $k = $this->taban($salonId, $d)->selectRaw(self::TOPLAM_SQL.',
            AVG(CASE WHEN cevaplandi = 1 THEN nps_skoru END) as ort_nps,
            SUM(cevaplandi = 1 AND google_yonlendirildi = 1) as google,
            SUM(cevaplandi = 1 AND '.AnketGonderimSorgusu::DUSUK_PUAN_SQL.') as kotu', [$esik->uyariNps, $esik->uyariCsat])->first();

        $gonderim = (int) $k->gonderim;
        $cevap = (int) $k->cevap;
        [$p, $pa, $de] = [(int) $k->promoter, (int) $k->pasif, (int) $k->detractor];

        return [
            'gun'                    => $d->gunSayisi(),
            'toplamGonderim'         => $gonderim,
            'toplamCevap'            => $cevap,
            'cevapOrani'             => OzetHesap::oran($cevap, $gonderim),
            'npsSkor'                => OzetHesap::nps($p, $pa, $de),
            'ortNps'                 => $k->ort_nps === null ? null : round((float) $k->ort_nps, 1),
            'ortCsat'                => $k->csat === null ? null : round((float) $k->csat, 2),
            'promoter'               => $p,
            'passive'                => $pa,
            'detractor'              => $de,
            'trend'                  => $this->trend($salonId),
            'googleTiklamalar'       => (int) $k->google,
            'kotuPuanUyarilari'      => (int) $k->kotu,
            'reputationPremiumAktif' => (bool) $salon->reputation_premium_aktif,
            'googleUrlVar'           => ! empty($salon->google_review_url),
            'uyariTelefon'           => $salon->kotu_puan_uyari_telefon ?: null,
            'donem'                  => $d->dizi(),
        ];
    }

    /** Son 7 gunun gunluk cevap sayisi (eskiden gun basina ayri sorgu); eskiden yeniye. */
    private function trend(int $salonId): array
    {
        $bas = now()->subDays(self::TREND_GUN - 1)->startOfDay();
        $sayim = DB::table('anket_gonderimleri')->where('salon_id', $salonId)->where('cevaplandi', 1)
            ->whereBetween('cevap_zamani', [$bas, now()->endOfDay()])
            ->selectRaw('DATE(cevap_zamani) as gun, COUNT(*) as n')->groupBy('gun')->pluck('n', 'gun');

        $trend = [];
        for ($i = 0; $i < self::TREND_GUN; $i++) {
            $trend[] = (int) ($sayim[$bas->copy()->addDays($i)->toDateString()] ?? 0);
        }

        return $trend;
    }
}
