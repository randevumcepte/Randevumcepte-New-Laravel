<?php

namespace App\Services\MusteriHesap;

use App\Services\Paket\PaketKullanimi;
use Illuminate\Support\Facades\DB;

/**
 * Musterinin kendi seans kartlari (v1 seanslar, musteriMi). Alan adlari Flutter SeansTakip.fromJson ile ayni,
 * tutar alani yok. Kapsam marka subeleri ya da verilen salon; musteri token'dan.
 */
class MusteriSeansServisi
{
    private const SAYFA = 10;

    public function __construct(private PaketKullanimi $kullanim) {}

    public function liste(int $userId, ?string $bundle, ?int $salonId, int $sayfa): array
    {
        $adisyonlar = DB::table('adisyonlar')->where('user_id', $userId)
            ->when($bundle, fn ($q) => $q->whereIn('salon_id', DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id')))
            ->when(! $bundle && $salonId, fn ($q) => $q->where('salon_id', $salonId))
            ->when(! $bundle && ! $salonId, fn ($q) => $q->whereRaw('1 = 0'))
            ->pluck('id');

        $kalemler = collect()
            ->merge(DB::table('adisyon_paketler as ap')->join('paketler as p', 'p.id', '=', 'ap.paket_id')->whereIn('ap.adisyon_id', $adisyonlar)->where('ap.seans_sayisi', '>', 0)
                ->get(['ap.id', 'ap.adisyon_id', 'ap.paket_id', 'ap.seans_sayisi', 'ap.bekleyen_seans', 'ap.created_at', 'p.paket_adi as ad'])->map($this->tipli('paket')))
            ->merge(DB::table('adisyon_hizmetler as ah')->join('hizmetler as h', 'h.id', '=', 'ah.hizmet_id')->whereIn('ah.adisyon_id', $adisyonlar)->where('ah.seans_sayisi', '>', 0)
                ->get(['ah.id', 'ah.adisyon_id', 'ah.hizmet_id', 'ah.seans_sayisi', 'ah.bekleyen_seans', 'ah.created_at', 'h.hizmet_adi as ad'])->map($this->tipli('hizmet')))
            ->sortByDesc(fn ($k) => (string) $k->created_at)->values();

        $sonSayfa = max(1, (int) ceil($kalemler->count() / self::SAYFA));
        $sayfa = max(1, $sayfa);
        $sayfaKalemleri = $kalemler->forPage($sayfa, self::SAYFA)->values();

        $paketIdler = $sayfaKalemleri->where('tip', 'paket')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $hizmetIdler = $sayfaKalemleri->where('tip', 'hizmet')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $seanslar = $this->seanslar($paketIdler, $hizmetIdler);
        $durumlar = $this->kullanim->durumlar($paketIdler);
        $paketHizmetleri = DB::table('paket_hizmetler as ph')->join('hizmetler as h', 'h.id', '=', 'ph.hizmet_id')
            ->whereIn('ph.paket_id', $sayfaKalemleri->where('tip', 'paket')->pluck('paket_id'))
            ->get(['ph.paket_id', 'h.id', 'h.hizmet_adi'])->groupBy('paket_id');
        $musteri = (array) DB::table('users')->where('id', $userId)->first(['id', 'name']);

        return [
            'data'         => $sayfaKalemleri->map(fn ($k) => $this->kart($k, $seanslar, $durumlar, $paketHizmetleri, $musteri))->all(),
            'current_page' => $sayfa,
            'last_page'    => $sonSayfa,
            'total'        => $kalemler->count(),
        ];
    }

    private function tipli(string $tip): \Closure
    {
        return function (object $kalem) use ($tip) {
            $kalem->tip = $tip;

            return $kalem;
        };
    }

    private function kart(object $k, array $seanslar, array $durumlar, $paketHizmetleri, array $musteri): array
    {
        $paketMi = $k->tip === 'paket';
        $satirlar = $seanslar[$k->tip][$k->id] ?? [];
        $gelinen = count(array_filter($satirlar, fn ($s) => $s['geldi'] === 1));
        $gelinmeyen = count(array_filter($satirlar, fn ($s) => $s['geldi'] === 0 || $s['iptal']));
        $durum = $paketMi ? ($durumlar[$k->id] ?? null) : null;
        $sinirsiz = (bool) ($durum['sinirsiz'] ?? false);
        $bekleyen = $sinirsiz
            ? count(array_filter($satirlar, fn ($s) => $s['geldi'] === null && ! $s['iptal']))
            : (int) $k->bekleyen_seans - $gelinen - $gelinmeyen;

        $kart = [
            'adisyon'               => (int) $k->adisyon_id,
            'musteri'               => $musteri,
            'paket'                 => $k->ad.($paketMi ? ' (P)' : ' (H)'),
            'seanslar'              => $satirlar,
            'toplamSeansSayisi'     => (int) $k->seans_sayisi,
            'bekleyenSeansSayisi'   => $bekleyen,
            'gelinenSeansSayisi'    => $gelinen,
            'gelinmeyenSeansSayisi' => $gelinmeyen,
            'tip'                   => $k->tip,
            'paketHizmetId'         => (int) $k->id,
        ];

        return $paketMi
            ? $kart + [
                'sinirsiz'  => $sinirsiz ? 1 : 0,
                'sure'      => $durum['sure'] ?? '',
                'baslangic' => $durum['baslangic'] ?? null,
                'bitis'     => $durum['bitis'] ?? null,
                'hizmetler' => collect($paketHizmetleri[$k->paket_id] ?? [])->map(fn ($h) => ['id' => (int) $h->id, 'hizmet_adi' => $h->hizmet_adi])->values()->all(),
            ]
            : $kart + ['hizmetler' => [['id' => (int) $k->hizmet_id, 'hizmet_adi' => $k->ad]]];
    }

    /** @return array{paket:array,hizmet:array} kalem id'sine gore seans satirlari */
    private function seanslar(array $paketIdler, array $hizmetIdler): array
    {
        $satirlar = DB::table('adisyon_paket_seanslar as s')
            ->leftJoin('hizmetler as h', 'h.id', '=', 's.hizmet_id')
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 's.personel_id')
            ->leftJoin('cihazlar as c', 'c.id', '=', 's.cihaz_id')
            ->leftJoin('odalar as o', 'o.id', '=', 's.oda_id')
            ->where(fn ($q) => $q->whereIn('s.adisyon_paket_id', $paketIdler)->orWhereIn('s.adisyon_hizmet_id', $hizmetIdler))
            ->orderBy('s.seans_no')->orderBy('s.id')
            ->get(['s.id', 's.adisyon_paket_id', 's.adisyon_hizmet_id', 's.hizmet_id', 's.seans_no', 's.seans_tarih', 's.seans_saat', 's.geldi', 's.iptal', 's.randevu_id',
                'h.hizmet_adi', 'p.personel_adi', 'c.cihaz_adi', 'o.oda_adi']);

        $sonuc = ['paket' => [], 'hizmet' => []];
        foreach ($satirlar as $s) {
            $satir = [
                'id' => (int) $s->id, 'hizmet_id' => $s->hizmet_id, 'seans_no' => $s->seans_no, 'seans_tarih' => $s->seans_tarih, 'seans_saat' => $s->seans_saat,
                'geldi' => $s->geldi === null ? null : (int) $s->geldi, 'iptal' => (bool) $s->iptal, 'randevu_id' => $s->randevu_id,
                'hizmet'   => ['id' => $s->hizmet_id, 'hizmet_adi' => $s->hizmet_adi],
                'personel' => $s->personel_adi !== null ? ['personel_adi' => $s->personel_adi] : null,
                'cihaz'    => $s->cihaz_adi !== null ? ['cihaz_adi' => $s->cihaz_adi] : null,
                'oda'      => $s->oda_adi !== null ? ['oda_adi' => $s->oda_adi] : null,
            ];
            if ($s->adisyon_paket_id) {
                $sonuc['paket'][$s->adisyon_paket_id][] = $satir;
            } elseif ($s->adisyon_hizmet_id) {
                $sonuc['hizmet'][$s->adisyon_hizmet_id][] = $satir;
            }
        }

        return $sonuc;
    }
}
