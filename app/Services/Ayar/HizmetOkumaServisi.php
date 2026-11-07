<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\AyarSozluk;
use App\Exceptions\BulunamadiException;
use App\Support\YerelListe;
use Illuminate\Support\Facades\DB;

/**
 * Ayarlar → Hizmetler OKUMA — eski ayarlar() hizmet_gruplari / eklenebilir_hizmetler blogu,
 * v1 hizmet_liste_getir / seciliolmayanhizmetlerigetir / hizmetkategorileri'nin TEK karsiligi.
 *
 * Satir = salon_sunulan_hizmetler kaydi (id) + hizmetler katalogu (hizmet_id). Alanlar Flutter
 * Hizmet modeliyle uyumlu (id, hizmet_id, hizmet_adi, hizmet_kategorisi, sure_dk, fiyat,
 * personel, cihaz, ozel_hizmet) + yapisal personeller[] / cihazlar[].
 * ozel_hizmet = bu salonun kendi tanimladigi hizmet (ad/kategori dogrudan duzenlenir).
 */
class HizmetOkumaServisi
{
    /**
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $o
     */
    public function listele(int $salonId, array $o, ?int $kategoriId = null, bool $tumu = false): array
    {
        $satirlar = $this->satirlar($salonId);
        if ($kategoriId) {
            $satirlar = array_values(array_filter($satirlar, fn ($s) => $s['kategori_id'] === $kategoriId));
        }
        $ozet = [
            'toplam_hizmet'   => count($satirlar),
            'toplam_kategori' => count(array_unique(array_column($satirlar, 'kategori_id'))),
        ];
        if ($tumu) {
            return ['data' => $satirlar, 'total' => count($satirlar), 'ozet' => $ozet];
        }

        return YerelListe::uygula($satirlar, $o, ['hizmet_adi', 'hizmet_kategorisi', 'personel', 'cihaz']) + ['ozet' => $ozet];
    }

    public function detay(int $salonId, int $salonHizmetId): array
    {
        $satir = $this->satirlar($salonId, $salonHizmetId)[0] ?? null;
        if (! $satir) {
            throw new BulunamadiException('Hizmet bulunamadı');
        }

        return $satir;
    }

    /** Form secenekleri: kategoriler (global), aktif personel + cihazlar, cinsiyetler, eklenebilir havuz. */
    public function formVerisi(int $salonId): array
    {
        return [
            'kategoriler' => $this->kategoriler(),
            'personeller' => DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)
                ->orderBy('personel_adi')->get(['id', 'personel_adi'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'personel_adi' => (string) $p->personel_adi])->all(),
            'cihazlar' => DB::table('cihazlar')->where('salon_id', $salonId)->where('aktifmi', 1)
                ->orderBy('takvim_sirasi')->get(['id', 'cihaz_adi'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'cihaz_adi' => (string) $c->cihaz_adi])->all(),
            'cinsiyetler' => AyarSozluk::CINSIYETLER,
            'havuz'       => $this->havuz($salonId),
        ];
    }

    /** @return list<array{id:int, ad:string}> */
    public function kategoriler(): array
    {
        return DB::table('hizmet_kategorisi')->orderBy('hizmet_kategorisi_adi')->get(['id', 'hizmet_kategorisi_adi'])
            ->map(fn ($k) => ['id' => (int) $k->id, 'ad' => trim((string) $k->hizmet_kategorisi_adi)])->all();
    }

    /**
     * Salonun aktif sunmadigi, eklenebilir hizmetler (kategoriye gruplu): sistem havuzu
     * (salon_id NULL, ozel degil) + bu salonun pasif ozel hizmetleri. Baska salonlarin ozel
     * hizmetleri LISTELENMEZ (eskiyle ayni). fiyat = katalog fiyati (mobil form on doldurur).
     */
    public function havuz(int $salonId): array
    {
        $aktif = DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('aktif', 1)->pluck('hizmet_id');
        $hizmetler = DB::table('hizmetler as h')
            ->leftJoin('hizmet_kategorisi as k', 'k.id', '=', 'h.hizmet_kategori_id')
            ->whereNotIn('h.id', $aktif)
            ->where('h.id', '!=', AyarSozluk::HAVUZ_HARIC_HIZMET_ID)
            ->where(fn ($q) => $q
                ->where(fn ($o) => $o->where('h.ozel_hizmet', 1)->where('h.salon_id', $salonId))
                ->orWhere(fn ($s) => $s->whereNull('h.salon_id')->whereRaw('COALESCE(h.ozel_hizmet, 0) = 0')))
            ->orderBy('k.hizmet_kategorisi_adi')->orderBy('h.hizmet_adi')
            ->select('h.*', 'k.hizmet_kategorisi_adi')->get();

        return $hizmetler->groupBy('hizmet_kategori_id')->map(fn ($g) => [
            'kategori_id'  => $g->first()->hizmet_kategori_id !== null ? (int) $g->first()->hizmet_kategori_id : null,
            'kategori_adi' => trim((string) ($g->first()->hizmet_kategorisi_adi ?? 'Kategorisiz')),
            'hizmetler'    => $g->map(fn ($h) => ['id' => (int) $h->id, 'hizmet_adi' => trim((string) $h->hizmet_adi), 'sure_dk' => $h->sure_dk !== null ? (int) $h->sure_dk : null,
                'fiyat' => $h->fiyat ?? null, 'ozel_hizmet' => (int) ($h->ozel_hizmet ?? 0) === 1])->values()->all(),
        ])->values()->all();
    }

    /** @return list<array> */
    private function satirlar(int $salonId, ?int $tekId = null): array
    {
        $sorgu = DB::table('salon_sunulan_hizmetler as sh')
            ->join('hizmetler as h', 'h.id', '=', 'sh.hizmet_id')
            ->leftJoin('hizmet_kategorisi as k', 'k.id', '=', DB::raw('COALESCE(sh.hizmet_kategori_id, h.hizmet_kategori_id)'))
            ->where('sh.salon_id', $salonId)->where('sh.aktif', 1)->whereNull('sh.santral_hizmeti');
        if ($tekId) {
            $sorgu->where('sh.id', $tekId);
        }
        $liste = $sorgu->orderBy('k.hizmet_kategorisi_adi')->orderBy('h.hizmet_adi')->get([
            'sh.id', 'sh.hizmet_id', 'sh.sure_dk', 'sh.baslangic_fiyat', 'sh.maliyet', 'h.hizmet_adi', 'h.cinsiyet',
            'h.ozel_hizmet', 'h.salon_id as hizmet_salon_id', 'k.id as kategori_id', 'k.hizmet_kategorisi_adi',
        ]);
        if ($liste->isEmpty()) {
            return [];
        }

        $hizmetIdler = $liste->pluck('hizmet_id')->unique()->all();
        $personel = DB::table('personel_sunulan_hizmetler as ps')->join('salon_personelleri as p', 'p.id', '=', 'ps.personel_id')
            ->where('p.salon_id', $salonId)->whereIn('ps.hizmet_id', $hizmetIdler)->orderBy('p.personel_adi')
            ->get(['ps.hizmet_id', 'p.id', 'p.personel_adi'])->groupBy('hizmet_id');
        $cihaz = DB::table('cihaz_sunulan_hizmetler as cs')->join('cihazlar as c', 'c.id', '=', 'cs.cihaz_id')
            ->where('c.salon_id', $salonId)->where('c.aktifmi', 1)->whereIn('cs.hizmet_id', $hizmetIdler)->orderBy('c.cihaz_adi')
            ->get(['cs.hizmet_id', 'c.id', 'c.cihaz_adi'])->groupBy('hizmet_id');

        return $liste->map(function ($s) use ($salonId, $personel, $cihaz) {
            $p = $personel->get($s->hizmet_id, collect())->unique('id')->map(fn ($x) => ['id' => (int) $x->id, 'personel_adi' => (string) $x->personel_adi])->values()->all();
            $c = $cihaz->get($s->hizmet_id, collect())->unique('id')->map(fn ($x) => ['id' => (int) $x->id, 'cihaz_adi' => (string) $x->cihaz_adi])->values()->all();

            return [
                'id'                => (int) $s->id,
                'hizmet_id'         => (int) $s->hizmet_id,
                'hizmet_adi'        => trim((string) $s->hizmet_adi),
                'kategori_id'       => $s->kategori_id !== null ? (int) $s->kategori_id : null,
                'hizmet_kategorisi' => trim((string) ($s->hizmet_kategorisi_adi ?? 'Kategorisiz')),
                'sure_dk'           => $s->sure_dk !== null ? (int) $s->sure_dk : null,
                'fiyat'             => (float) ($s->baslangic_fiyat ?? 0),
                'maliyet'           => (float) ($s->maliyet ?? 0),
                'cinsiyet'          => $s->cinsiyet !== null && $s->cinsiyet !== '' ? (int) $s->cinsiyet : null,
                'cinsiyet_etiket'   => AyarSozluk::cinsiyetEtiketi($s->cinsiyet),
                'ozel_hizmet'       => (int) $s->ozel_hizmet === 1 && (int) $s->hizmet_salon_id === $salonId,
                'personeller'       => $p,
                'cihazlar'          => $c,
                'personel'          => implode(', ', array_column($p, 'personel_adi')),
                'cihaz'             => implode(', ', array_column($c, 'cihaz_adi')),
            ];
        })->all();
    }
}
