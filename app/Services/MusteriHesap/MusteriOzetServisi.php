<?php

namespace App\Services\MusteriHesap;

use App\Domain\Musteri\MusteriKategorisi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Musteri ana sayfasi ozeti (v1 musteriozet): okunmamis bildirim, kategori, sadakat puani. Kapsam markanin
 * subeleri, kimlik token'dan. Stüdyo salonunda tahsilat tutari 0 tutuldugu icin odeme = odendi=1 adisyon.
 */
class MusteriOzetServisi
{
    public function ozet(int $userId, string $bundle): array
    {
        $salonlar = DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $odeme = $this->odemeSayisi($userId, $salonlar);
        [$kategori, $etiket] = MusteriKategorisi::coz($odeme);

        return [
            'musteriokunmamis' => DB::table('bildirimler')->whereIn('salon_id', $salonlar)->where('user_id', $userId)->where('okundu', 0)->count(),
            'kategori'         => $kategori,
            'kategori_label'   => $etiket,
            'tahsilat_sayisi'  => $odeme,
            'sadakat_puani'    => (int) DB::table('salon_sadakat_puanlari')->where('user_id', $userId)->whereIn('salon_id', $salonlar)->sum('puan'),
        ];
    }

    /** @param  int[]  $salonlar */
    private function odemeSayisi(int $userId, array $salonlar): int
    {
        $studyo = Schema::hasColumn('adisyonlar', 'odendi')
            ? DB::table('salonlar')->whereIn('id', $salonlar)->where('studyo_modu', 1)->pluck('id')->map(fn ($x) => (int) $x)->all()
            : [];
        $normal = array_values(array_diff($salonlar, $studyo));

        return DB::table('tahsilatlar')->where('user_id', $userId)->whereIn('salon_id', $normal)->count()
            + ($studyo ? DB::table('adisyonlar')->where('user_id', $userId)->whereIn('salon_id', $studyo)->where('odendi', 1)->count() : 0);
    }
}
