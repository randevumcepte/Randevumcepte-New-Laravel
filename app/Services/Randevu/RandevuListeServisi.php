<?php

namespace App\Services\Randevu;

use App\Domain\Randevu\RandevuDurumu;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/**
 * Randevu LISTESI (salt-okuma, sayfali) — eski StoreAdminController::randevu_liste_getir
 * sutunlariyla: tarih, saat, durum, hizmetler, personel/cihaz/oda, randevu notu,
 * olusturan, olusturulma. Ilk tuketici: musteri detay "Randevular" sekmesi.
 *
 * Eskiden farki: HTML uretmez (durum = {kod, etiket, renk}); musteri kartinda "son 300"
 * siniri yerine sunucu sayfalamasi. Tablo aramasi (ara) satirda GORUNEN icerikte: tarih
 * (gg.aa.yyyy), saat, durum etiketi, hizmet, personel/cihaz/oda, not, olusturan. Personel kapsami: randevu.tum_personel_gor yoksa
 * yalnizca kendi (asil — yardimci olmayan) kalemlerinin bulundugu randevular.
 */
class RandevuListeServisi
{
    public const SIRALANABILIR = ['tarih', 'olusturulma'];

    /**
     * @param  array{musteri_id?:?int, kisit_personel_id?:?int}  $f
     * @param  array{onayla?:bool, duzenle_iptal?:bool}  $yetki  satir `aksiyonlar` icin
     */
    public function listele(int $salonId, array $f, ListeIstegi $l, array $yetki = []): array
    {
        $q = DB::table('randevular as r')->where('r.salon_id', $salonId)
            ->when($f['musteri_id'] ?? null, fn ($q, $uid) => $q->where('r.user_id', $uid))
            ->when($f['kisit_personel_id'] ?? null, fn ($q, $pid) => $q->whereExists(fn ($e) => $e->selectRaw('1')
                ->from('randevu_hizmetler as rk')->whereColumn('rk.randevu_id', 'r.id')
                ->where('rk.personel_id', $pid)->whereRaw('COALESCE(rk.yardimci_personel,0) != 1')))
            ->when($l->likeDeseni(), fn ($q, $like) => $this->ara($q, $like, (string) $l->ara));

        $toplam = (clone $q)->count();

        $yon = $l->yon;
        $l->sirala === 'olusturulma'
            ? $q->orderBy('r.created_at', $yon)
            : $q->orderBy('r.tarih', $yon)->orderBy('r.saat', $yon);

        $randevular = $q->orderBy('r.id', $yon)->forPage($l->sayfa, $l->sayfaBoyu)
            ->leftJoin('isletmeyetkilileri as y', 'y.id', '=', 'r.olusturan_personel_id')
            ->get(['r.id', 'r.tarih', 'r.saat', 'r.durum', 'r.randevuya_geldi', 'r.personel_notu', 'r.web', 'r.uygulama',
                'r.created_at', 'y.name as olusturan_adi']);

        $kalemler = $this->kalemler($randevular->pluck('id')->all());

        return [
            'data' => $randevular->map(function ($r) use ($kalemler, $yetki) {
                $k = $kalemler[$r->id] ?? ['hizmetler' => [], 'kaynaklar' => [], 'telafi' => null];

                return [
                    'id'            => (int) $r->id,
                    'tarih'         => $r->tarih,
                    'saat'          => $r->saat ? substr((string) $r->saat, 0, 5) : null,
                    'durum'         => RandevuDurumu::etiket($r->durum, $r->randevuya_geldi),
                    'telafi'        => $k['telafi'],
                    'hizmetler'     => $k['hizmetler'],
                    'kaynaklar'     => $k['kaynaklar'],
                    'randevu_notu'  => trim((string) $r->personel_notu) === '' ? null : $r->personel_notu,
                    'olusturan'     => (int) $r->web === 1 ? 'Web' : ((int) $r->uygulama === 1 ? 'Uygulama' : $r->olusturan_adi),
                    'olusturulma'   => $r->created_at,
                    'aksiyonlar'    => RandevuDurumu::aksiyonlar($r->durum, $r->randevuya_geldi,
                        (bool) ($yetki['onayla'] ?? false), (bool) ($yetki['duzenle_iptal'] ?? false)),
                ];
            })->all(),
        ] + ListeIstegi::meta($l->sayfa, $l->sayfaBoyu, $toplam);
    }

    /** Tablo aramasi — kolonlar listede gosterilenlerle ayni. */
    private function ara($q, string $like, string $ham): void
    {
        $durumlar = RandevuDurumu::etiketeUyanlar($ham);

        $q->where(function ($w) use ($like, $durumlar) {
            $w->whereRaw("DATE_FORMAT(r.tarih, '%d.%m.%Y') LIKE ?", [$like])
                ->orWhereRaw("TIME_FORMAT(r.saat, '%H:%i') LIKE ?", [$like])
                ->orWhere('r.personel_notu', 'like', $like)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('isletmeyetkilileri as oy')
                    ->whereColumn('oy.id', 'r.olusturan_personel_id')->where('oy.name', 'like', $like))
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('randevu_hizmetler as ak')
                    ->leftJoin('hizmetler as ah', 'ah.id', '=', 'ak.hizmet_id')
                    ->leftJoin('salon_personelleri as ap', 'ap.id', '=', 'ak.personel_id')
                    ->leftJoin('cihazlar as ac', 'ac.id', '=', 'ak.cihaz_id')
                    ->leftJoin('odalar as ao', 'ao.id', '=', 'ak.oda_id')
                    ->whereColumn('ak.randevu_id', 'r.id')
                    ->where(fn ($x) => $x->where('ah.hizmet_adi', 'like', $like)->orWhere('ap.personel_adi', 'like', $like)
                        ->orWhere('ac.cihaz_adi', 'like', $like)->orWhere('ao.oda_adi', 'like', $like)));
            foreach ($durumlar as [$durum, $geldi]) {
                $w->orWhere(function ($d) use ($durum, $geldi) {
                    $d->where('r.durum', $durum);
                    if ($geldi === false) {
                        $d->whereNull('r.randevuya_geldi');
                    } elseif ($geldi !== null) {
                        $d->where('r.randevuya_geldi', $geldi);
                    }
                });
            }
        });
    }

    /**
     * Sayfadaki randevularin kalemleri tek sorguda: hizmet adlari (dusum xN), personel /
     * cihaz / oda adlari, telafi rozeti (telafi randevusu | telafi verildi).
     *
     * @param  int[]  $ids
     */
    private function kalemler(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $satirlar = DB::table('randevu_hizmetler as rh')
            ->leftJoin('hizmetler as h', 'h.id', '=', 'rh.hizmet_id')
            ->leftJoin('salon_personelleri as sp', 'sp.id', '=', 'rh.personel_id')
            ->leftJoin('cihazlar as c', 'c.id', '=', 'rh.cihaz_id')
            ->leftJoin('odalar as o', 'o.id', '=', 'rh.oda_id')
            ->whereIn('rh.randevu_id', $ids)->orderBy('rh.id')
            ->get(['rh.randevu_id', 'h.hizmet_adi', 'rh.dusum_miktari', 'sp.personel_adi', 'c.cihaz_adi', 'o.oda_adi',
                'rh.telafi_kaynak_rh_id', 'rh.telafi_devir_yapildi']);

        $sonuc = [];
        foreach ($satirlar->groupBy('randevu_id') as $rid => $grup) {
            $telafi = null;
            if ($grup->contains(fn ($s) => $s->telafi_kaynak_rh_id !== null)) {
                $telafi = 'telafi_randevusu';
            } elseif ($grup->contains(fn ($s) => (int) $s->telafi_devir_yapildi === 1)) {
                $telafi = 'telafi_verildi';
            }
            $sonuc[$rid] = [
                'hizmetler' => $grup->filter(fn ($s) => $s->hizmet_adi)->map(fn ($s) => (int) $s->dusum_miktari > 1
                    ? $s->hizmet_adi.' x'.(int) $s->dusum_miktari : $s->hizmet_adi)->unique()->values()->all(),
                'kaynaklar' => $grup->flatMap(fn ($s) => [$s->personel_adi, $s->cihaz_adi, $s->oda_adi])
                    ->filter()->unique()->values()->all(),
                'telafi'    => $telafi,
            ];
        }

        return $sonuc;
    }
}
