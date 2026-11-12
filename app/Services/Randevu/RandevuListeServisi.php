<?php

namespace App\Services\Randevu;

use App\Models\Randevular;
use App\Domain\Randevu\RandevuDurumu;
use App\Domain\Randevu\RandevuZamani;
use App\Services\Musteri\PortfoyKapsami;
use App\Services\Panel\RandevuModulu;
use App\Services\Yetki\YetkiServisi;
use App\Support\ListeIstegi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Facades\DB;

/**
 * Randevu LISTESI (salt-okuma, sayfali) — eski StoreAdminController::randevu_liste_getir
 * sutunlariyla: tarih, saat, durum, hizmetler, personel/cihaz/oda, randevu notu,
 * olusturan, olusturulma (+ musteri / telefon). Tuketiciler: Randevular listesi
 * (/v2/randevular — eski /isletmeyonetim/randevular-liste) ve musteri detay "Randevular".
 * Filtreler eski randevu_liste_filtre ile ayni: zaman (RandevuZamani), kaynak
 * (salon/web/uygulama), durum (RandevuDurumu::FILTRELER), hizmet.
 *
 * Eskiden farki: HTML uretmez (durum = {kod, etiket, renk}); musteri kartinda "son 300"
 * siniri yerine sunucu sayfalamasi. Tablo aramasi (ara) satirda GORUNEN icerikte: tarih
 * (gg.aa.yyyy), saat, durum etiketi, hizmet, personel/cihaz/oda, not, olusturan. Personel kapsami: randevu.tum_personel_gor yoksa
 * yalnizca kendi (asil — yardimci olmayan) kalemlerinin bulundugu randevular.
 */
class RandevuListeServisi
{
    public const SIRALANABILIR = ['tarih', 'olusturulma', 'musteri'];

    /** Kisitli kullanicinin personel kaydi yoksa kullanilan, hicbir kalemle eslesmeyen personel id'si. */
    private const KISIT_HICBIRI = -1;

    /** Olusturma kaynagi filtresi: randevular.<kolon> = 1 (eski: salon / web / uygulama). */
    public const KAYNAKLAR = ['salon' => 'Salon', 'web' => 'Web', 'uygulama' => 'Uygulama'];

    public function __construct(private YetkiServisi $yetki, private PortfoyKapsami $portfoy, private RandevuModulu $randevuModulu) {}

    /**
     * Kullanicinin liste kapsami ve satir yetkileri — TEK yer (Randevular listesi ve
     * musteri karti ayni kurali kullanir). Eski: randevu.tum_personel_gor yoksa yalniz
     * kendi kalemleri; telefon musteri.telefon_gor yoksa maskeli.
     *
     * @return array{kisit_personel_id:?int, yetki:array{onayla:bool, duzenle_iptal:bool, telefon_gor:bool}}
     */
    public function kapsam(int $yetkiliId, int $salonId): array
    {
        $var = fn (string $k) => $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, $k);

        // Fail-closed: tum_personel_gor yok ve bu salonda personel kaydi da yoksa hicbir satir eslesmez (kisitsiz kalmaz).
        $kisit = $var('randevu.tum_personel_gor') ? null : ($this->portfoy->personelId($yetkiliId, $salonId) ?? self::KISIT_HICBIRI);

        return [
            'kisit_personel_id' => $kisit,
            'yetki'             => [
                'onayla'        => $var('randevu.onayla'),
                'duzenle_iptal' => $var('randevu.duzenle_iptal'),
                'telefon_gor'   => $var('musteri.telefon_gor'),
            ],
        ];
    }

    /** Filtre secenekleri (web + Flutter ayni liste; tarih araligi sunucuda cozulur). */
    public function secenekler(int $salonId): array
    {
        return [
            'zamanlar'          => RandevuZamani::secenekListesi(),
            'varsayilan_zaman'  => RandevuZamani::VARSAYILAN,
            'durumlar'          => RandevuDurumu::filtreSecenekleri(),
            'kaynaklar'         => array_map(fn ($k, $a) => ['kod' => $k, 'ad' => $a], array_keys(self::KAYNAKLAR), self::KAYNAKLAR),
            'hizmetler'         => DB::table('salon_sunulan_hizmetler as ss')->join('hizmetler as h', 'h.id', '=', 'ss.hizmet_id')
                ->where('ss.salon_id', $salonId)->where('ss.aktif', 1)
                ->groupBy('h.id', 'h.hizmet_adi')->orderBy('h.hizmet_adi')
                ->get(['h.id', 'h.hizmet_adi as ad'])->map(fn ($h) => ['id' => (int) $h->id, 'ad' => $h->ad])->all(),
        ];
    }

    /**
     * @param  array{musteri_id?:?int, kisit_personel_id?:?int, personel_id?:?int, cihaz_id?:?int, zaman?:?array, kaynak?:?string, durum?:?string, hizmet_id?:?int}  $f
     * @param  array{onayla?:bool, duzenle_iptal?:bool, telefon_gor?:bool}  $yetki  satir `aksiyonlar` + telefon
     */
    public function listele(int $salonId, array $f, ListeIstegi $l, array $yetki = []): array
    {
        // Tek kaynak ya da virgullu liste (herhangi biri).
        $kaynaklar = array_values(array_intersect(explode(',', (string) ($f['kaynak'] ?? '')), array_keys(self::KAYNAKLAR)));
        $durum = RandevuDurumu::filtre($f['durum'] ?? null);

        $q = DB::table('randevular as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->where('r.salon_id', $salonId)
            ->where('r.user_id', '!=', Randevular::KAPALI_SAAT_USER_ID)
            ->when($f['musteri_id'] ?? null, fn ($q, $uid) => $q->where('r.user_id', $uid))
            ->when($f['zaman'] ?? null, fn ($q, $z) => $q->whereBetween('r.tarih', [$z['baslangic'], $z['bitis']]))
            ->when($kaynaklar, fn ($q, $liste) => $q->where(function ($w) use ($liste) {
                foreach ($liste as $k) {
                    $w->orWhere('r.'.$k, 1);
                }
            }))
            ->when($durum, fn ($q, $d) => $this->durumKosulu($q, $d))
            ->when($f['hizmet_id'] ?? null, fn ($q, $hid) => $q->whereExists(fn ($e) => $e->selectRaw('1')
                ->from('randevu_hizmetler as fh')->whereColumn('fh.randevu_id', 'r.id')->where('fh.hizmet_id', $hid)))
            ->when(($f['personel_id'] ?? null) || ($f['cihaz_id'] ?? null), fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')
                ->from('randevu_hizmetler as sk')->whereColumn('sk.randevu_id', 'r.id')
                ->when($f['personel_id'] ?? null, fn ($x, $pid) => $x->where('sk.personel_id', $pid))
                ->when($f['cihaz_id'] ?? null, fn ($x, $cid) => $x->where('sk.cihaz_id', $cid))))
            ->when($f['kisit_personel_id'] ?? null, fn ($q, $pid) => $q->whereExists(fn ($e) => $e->selectRaw('1')
                ->from('randevu_hizmetler as rk')->whereColumn('rk.randevu_id', 'r.id')
                ->where('rk.personel_id', $pid)->whereRaw('COALESCE(rk.yardimci_personel,0) != 1')))
            ->when($l->likeDeseni(), fn ($q, $like) => $this->ara($q, $like, (string) $l->ara));
        // Randevu modulu + "Gecmis randevular gorunmesin" (liste ve musteri karti Randevular sekmesi).
        $this->randevuModulu->gecmisiSuz($q, $salonId, 'r.');

        $toplam = (clone $q)->count();

        $yon = $l->yon;
        match ($l->sirala) {
            'olusturulma' => $q->orderBy('r.created_at', $yon),
            'musteri'     => $q->orderBy('u.name', $yon),
            default       => $q->orderBy('r.tarih', $yon)->orderBy('r.saat', $yon),
        };

        $randevular = $q->orderBy('r.id', $yon)->forPage($l->sayfa, $l->sayfaBoyu)
            ->leftJoin('isletmeyetkilileri as y', 'y.id', '=', 'r.olusturan_personel_id')
            ->get(['r.id', 'r.tarih', 'r.saat', 'r.durum', 'r.randevuya_geldi', 'r.personel_notu', 'r.web', 'r.uygulama',
                'r.created_at', 'y.name as olusturan_adi', 'r.user_id', 'u.name as musteri_adi', 'u.cep_telefon']);
        $telGor = (bool) ($yetki['telefon_gor'] ?? false);

        $kalemler = $this->kalemler($randevular->pluck('id')->all());

        return [
            'data' => $randevular->map(function ($r) use ($kalemler, $yetki, $telGor) {
                $k = $kalemler[$r->id] ?? ['hizmetler' => [], 'kaynaklar' => [], 'telafi' => null];

                return [
                    'id'            => (int) $r->id,
                    'musteri'       => ['id' => (int) $r->user_id, 'ad' => $r->musteri_adi,
                        'telefon' => $telGor || ! $r->cep_telefon ? $r->cep_telefon : YetkiSabitleri::telefonMaskele((string) $r->cep_telefon)],
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
                ->orWhere('u.name', 'like', $like)
                ->orWhere('u.cep_telefon', 'like', $like)
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
            foreach ($durumlar as $d) {
                $w->orWhere(fn ($x) => $this->durumKosulu($x, $d));
            }
        });
    }

    /** [durum, geldi] kosulu (RandevuDurumu kodlamasi: geldi false=NULL, null=farketmez). */
    private function durumKosulu($q, array $d): void
    {
        [$durum, $geldi] = $d;
        $q->where('r.durum', $durum);
        if ($geldi === false) {
            $q->whereNull('r.randevuya_geldi');
        } elseif ($geldi !== null) {
            $q->where('r.randevuya_geldi', $geldi);
        }
    }

    /**
     * Sayfadaki randevularin kalemleri tek sorguda: hizmet adlari (dusum xN), personel /
     * cihaz / oda adlari, telafi rozeti (telafi randevusu | telafi verildi). Ozet listeleri de kullanir.
     *
     * @param  int[]  $ids
     */
    public function kalemler(array $ids): array
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
