<?php

namespace App\Services\Stok;

use App\Domain\Stok\StokSeviyesi;
use App\Models\StokHareketi;
use App\Models\Urunler;
use App\Support\ListeIstegi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stok OKUMA raporlari: ozet kartlari, dusuk stok listesi, hareket defteri,
 * urun satis grafigi. Hepsi salon kapsaminda (controller kapsam denetler).
 */
class StokRaporServisi
{
    /** Siralama beyaz listesi (hareket defteri). */
    public const HAREKET_SIRALANABILIR = ['tarih', 'miktar', 'hareket_tipi', 'urun_adi'];

    /**
     * Ozet kartlari. dusuk_stok = seviye kritik|dusuk (StokSeviyesi), tukenen = stok<=0.
     * Deger: stok × satis / alis fiyati (yalniz pozitif stok — eksi stok degeri dusurmez).
     */
    public function ozet(int $salonId): array
    {
        $urunler = Urunler::where('salon_id', $salonId)->where('aktif', 1)
            ->get(['id', 'stok_adedi', 'dusuk_stok_siniri', 'kritik_stok_siniri', 'fiyat', 'alis_fiyati']);

        $o = ['toplam_urun' => $urunler->count(), 'dusuk_stok' => 0, 'tukenen' => 0, 'toplam_satis_degeri' => 0.0, 'toplam_alis_degeri' => 0.0];
        foreach ($urunler as $u) {
            $stok = (float) $u->stok_adedi;
            $seviye = StokSeviyesi::hesapla($stok, self::sayi($u->dusuk_stok_siniri), self::sayi($u->kritik_stok_siniri));
            $o['tukenen'] += $seviye === StokSeviyesi::TUKENDI ? 1 : 0;
            $o['dusuk_stok'] += StokSeviyesi::uyariMi($seviye) ? 1 : 0;
            $o['toplam_satis_degeri'] += max(0, $stok) * (float) $u->fiyat;
            $o['toplam_alis_degeri'] += max(0, $stok) * (float) $u->alis_fiyati;
        }

        $bugun = StokHareketi::where('salon_id', $salonId)->where('hareket_tipi', 'satis')->where('tarih', '>=', Carbon::today())
            ->selectRaw('COALESCE(SUM(ABS(miktar)),0) adet, COALESCE(SUM(ABS(miktar) * COALESCE(birim_satis_fiyati,0)),0) tutar')->first();

        return [
            'toplam_urun'         => $o['toplam_urun'],
            'dusuk_stok'          => $o['dusuk_stok'],
            'tukenen'             => $o['tukenen'],
            'toplam_satis_degeri' => round($o['toplam_satis_degeri'], 2),
            'toplam_alis_degeri'  => round($o['toplam_alis_degeri'], 2),
            'bugun_satis_adet'    => (float) $bugun->adet,
            'bugun_satis_tutar'   => round((float) $bugun->tutar, 2),
        ];
    }

    /**
     * Hareket defteri (sayfali). Filtre: urun_id, depo_id, hareket_tipi, baslangic, bitis (Y-m-d).
     *
     * @param  array  $liste  ListeIstegi::dizi()
     */
    public function hareketler(int $salonId, array $filtre, array $liste): array
    {
        $q = StokHareketi::query()->from('stok_hareketleri as h')
            ->leftJoin('urunler as u', 'u.id', '=', 'h.urun_id')
            ->leftJoin('depolar as d', 'd.id', '=', 'h.depo_id')
            ->where('h.salon_id', $salonId)
            ->select('h.*', 'u.urun_adi', 'd.depo_adi');

        foreach (['urun_id', 'depo_id'] as $alan) {
            if (! empty($filtre[$alan])) {
                $q->where('h.'.$alan, (int) $filtre[$alan]);
            }
        }
        if (! empty($filtre['hareket_tipi'])) {
            $q->where('h.hareket_tipi', $filtre['hareket_tipi']);
        }
        if (! empty($filtre['baslangic'])) {
            $q->where('h.tarih', '>=', Carbon::parse($filtre['baslangic'])->startOfDay());
        }
        if (! empty($filtre['bitis'])) {
            $q->where('h.tarih', '<=', Carbon::parse($filtre['bitis'])->endOfDay());
        }
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn ($w) => $w->where('u.urun_adi', 'like', $like)->orWhere('h.aciklama', 'like', $like)->orWhere('d.depo_adi', 'like', $like));
        }

        $toplam = (clone $q)->count();
        $alan = in_array($liste['sirala'] ?? null, self::HAREKET_SIRALANABILIR, true) ? $liste['sirala'] : 'tarih';
        $q->orderBy($alan === 'urun_adi' ? 'u.urun_adi' : 'h.'.$alan, $liste['yon'] ?? 'desc')->orderByDesc('h.id');

        $satirlar = $q->forPage($liste['page'], $liste['per_page'])->get()->map(fn ($h) => StokSunumu::hareket($h))->all();

        return ['data' => $satirlar] + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam);
    }

    /** Urunun gunluk satis adet/tutari (varsayilan son 30 gun). */
    public function urunSatisRaporu(int $urunId, ?string $baslangic, ?string $bitis): array
    {
        $bas = $baslangic ? Carbon::parse($baslangic)->startOfDay() : Carbon::today()->subDays(30);
        $bit = $bitis ? Carbon::parse($bitis)->endOfDay() : Carbon::now()->endOfDay();

        return DB::table('stok_hareketleri')->where('urun_id', $urunId)->where('hareket_tipi', 'satis')
            ->whereBetween('tarih', [$bas, $bit])
            ->selectRaw('DATE(tarih) gun, SUM(ABS(miktar)) adet, SUM(ABS(miktar) * COALESCE(birim_satis_fiyati,0)) tutar')
            ->groupBy('gun')->orderBy('gun')->get()
            ->map(fn ($r) => ['gun' => $r->gun, 'adet' => (float) $r->adet, 'tutar' => round((float) $r->tutar, 2)])
            ->all();
    }

    private static function sayi(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }
}
