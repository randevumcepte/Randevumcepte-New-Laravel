<?php

namespace App\Services\Randevu;

use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\Randevular;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Randevu formu verisi (Faz 3b-2) — eski ApiController@randevuIcinGerekliVeriler
 * (isletme dali) ile AYNI veri ve filtre kurallari; KVKK icin musteri listesi
 * toplu donmez, ayri arama ucundan (min 2 karakter, maskeli telefon) gelir.
 *
 * Filtre haritalari (Flutter v2 editoruyle ayni anlam):
 *   personel_hizmet_map: {personel_id: [hizmet_id]} — HIC personele atanmamis
 *                        hizmetler TUM aktif personellere eklenir (herkes yapabilir).
 *   cihaz_hizmet_map:    {cihaz_id: [hizmet_id]}
 *   oda_hizmet_map:      {oda_id: [hizmet_id]}
 *   oda_personel_map:    {oda_id: [personel_id]}
 */
class RandevuFormServisi
{
    public function formVerisi(int $salonId, ?int $randevuId, bool $telGor): array
    {
        $randevu = $randevuId ? $this->randevuOnDoldur($salonId, $randevuId, $telGor) : null;
        $salon = DB::table('salonlar')->where('id', $salonId)->first(['randevu_takvim_turu', 'salon_turu_id']);

        return [
            // Satir alanlari salonun takvim turune gore (eski web modali ile ayni):
            // personel HER ZAMAN; oda turu 1/2 DEGILSE; cihaz turu 1/3 DEGILSE.
            'takvim_turu'    => (int) ($salon->randevu_takvim_turu ?? 0),
            'musteri_etiket' => in_array((int) ($salon->salon_turu_id ?? 0), [15, 28, 29], true) ? 'Danışan' : 'Müşteri',
            'hizmetler'   => $this->hizmetler($salonId, $randevuId),
            'personeller' => DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)
                ->orderBy('takvim_sirasi')->get(['id', 'personel_adi as ad'])->map(fn ($p) => ['id' => (int) $p->id, 'ad' => $p->ad])->all(),
            'odalar'      => DB::table('odalar')->where('salon_id', $salonId)->where('aktifmi', 1)->orderBy('takvim_sirasi')
                ->get(['id', 'oda_adi as ad', 'personel_id'])->map(fn ($o) => ['id' => (int) $o->id, 'ad' => $o->ad, 'personel_id' => $o->personel_id ? (int) $o->personel_id : null])->all(),
            'cihazlar'    => DB::table('cihazlar')->where('salon_id', $salonId)->where('aktifmi', 1)->orderBy('takvim_sirasi')
                ->get(['id', 'cihaz_adi as ad'])->map(fn ($c) => ['id' => (int) $c->id, 'ad' => $c->ad])->all(),
            'haritalar'   => $this->haritalar($salonId),
            'randevu'     => $randevu,
        ];
    }

    /** Musteri arama — yalniz salonun AKTIF portfoyu; min 2 karakter; telefon yetkiye gore maskeli. */
    public function musteriAra(int $salonId, string $q, bool $telGor): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            throw new GecersizIslemException('Aramak için en az 2 karakter girin.');
        }
        $rakam = preg_replace('/\D/', '', $q);

        return DB::table('users as u')->join('musteri_portfoy as mp', 'mp.user_id', '=', 'u.id')
            ->where('mp.salon_id', $salonId)->where('mp.aktif', 1)
            ->where(function ($w) use ($q, $rakam) {
                $w->where('u.name', 'like', '%'.$q.'%');
                if (strlen($rakam) >= 3) {
                    $w->orWhere('u.cep_telefon', 'like', '%'.$rakam.'%');
                }
            })
            ->orderBy('u.name')->limit(20)->get(['u.id', 'u.name', 'u.cep_telefon'])
            ->map(fn ($u) => [
                'id'      => (int) $u->id,
                'ad'      => $u->name,
                'telefon' => $telGor ? $u->cep_telefon : YetkiSabitleri::telefonMaskele((string) $u->cep_telefon),
            ])->all();
    }

    /**
     * Musterinin KALAN SEANSLI paket ve tek hizmet satislari (seans takibi) —
     * eski StoreAdminController@paketVarmiKontrolu'nun bolge-bazli formulu:
     *   tek hizmet: kalan = seans_sayisi − COUNT(APS adisyon_hizmet_id)
     *   paket     : bolge basina kalan = seans_sayisi − COUNT(APS adisyon_paket_id + hizmet_id)
     * (her APS satiri, geldi ne olursa olsun bir seans sayilir — eskiyle ayni).
     *
     * Bilincli fark: liste, kayitta GERCEKTEN baglanabilecek satislarla sinirli
     * (seans_sayisi > 0 ve otomatik_randevu_olusturuldu != 1 — satir bazinda);
     * eski liste bunlari da gosterip kayitta baglayamiyordu.
     *
     * @return array<int,array{tur:string,adi:string,adisyon_paket_id:?int,adisyon_hizmet_id:?int,paket_sure:?int,hizmetler:array}>
     */
    public function musteriPaketleri(int $salonId, int $userId): array
    {
        if (! DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)->exists()) {
            throw new GecersizIslemException('Müşteri bu işletmenin portföyünde değil.');
        }
        $salonSure = $this->salonHizmetSureleri($salonId);
        $sonuc = [];

        // Tek hizmet satislari
        $q = DB::table('adisyon_hizmetler as ah')->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')->join('hizmetler as h', 'h.id', '=', 'ah.hizmet_id')
            ->where('a.user_id', $userId)->where('a.salon_id', $salonId)->where('ah.seans_sayisi', '>', 0);
        $ahlar = RandevuOrtak::otomatikRandevuDegil($q, 'ah')->orderBy('a.tarih')->orderBy('ah.id')
            ->get(['ah.id', 'ah.hizmet_id', 'ah.seans_sayisi', 'ah.sure', 'h.hizmet_adi', 'a.tarih']);
        $kullanilanAh = DB::table('adisyon_paket_seanslar')->whereIn('adisyon_hizmet_id', $ahlar->pluck('id'))
            ->groupBy('adisyon_hizmet_id')->selectRaw('adisyon_hizmet_id, COUNT(*) as n')->pluck('n', 'adisyon_hizmet_id');
        foreach ($ahlar as $ah) {
            $kalan = max(0, (int) $ah->seans_sayisi - (int) ($kullanilanAh[$ah->id] ?? 0));
            if ($kalan <= 0) {
                continue;
            }
            $sure = (int) $ah->sure ?: ($salonSure[$ah->hizmet_id] ?? 30);
            $sonuc[] = [
                'tur' => 'hizmet', 'adi' => $ah->hizmet_adi, 'adisyon_paket_id' => null, 'adisyon_hizmet_id' => (int) $ah->id,
                'satis_tarihi' => $ah->tarih, 'paket_sure' => null,
                'hizmetler' => [['hizmet_id' => (int) $ah->hizmet_id, 'ad' => $ah->hizmet_adi, 'kalan' => $kalan, 'toplam' => (int) $ah->seans_sayisi, 'sure' => $sure]],
            ];
        }

        // Paket satislari (bolge = paketteki hizmet)
        $phSure = Schema::hasColumn('paket_hizmetler', 'sure');
        $pSure = Schema::hasColumn('paketler', 'sure');
        $q = DB::table('adisyon_paketler as ap')->join('adisyonlar as a', 'a.id', '=', 'ap.adisyon_id')->join('paketler as p', 'p.id', '=', 'ap.paket_id')
            ->where('a.user_id', $userId)->where('a.salon_id', $salonId)->where('ap.seans_sayisi', '>', 0);
        $aplar = RandevuOrtak::otomatikRandevuDegil($q, 'ap')->orderBy('a.tarih')->orderBy('ap.id')
            ->get(array_merge(['ap.id', 'ap.paket_id', 'ap.seans_sayisi', 'p.paket_adi', 'a.tarih'], $pSure ? ['p.sure as paket_sure'] : []));
        foreach ($aplar as $ap) {
            $bolgeler = DB::table('paket_hizmetler as ph')->join('hizmetler as h', 'h.id', '=', 'ph.hizmet_id')->where('ph.paket_id', $ap->paket_id)
                ->get(array_merge(['ph.hizmet_id', 'h.hizmet_adi'], $phSure ? ['ph.sure'] : []));
            $kullanilan = DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $ap->id)
                ->groupBy('hizmet_id')->selectRaw('hizmet_id, COUNT(*) as n')->pluck('n', 'hizmet_id');
            $hizmetler = [];
            foreach ($bolgeler as $b) {
                $kalan = max(0, (int) $ap->seans_sayisi - (int) ($kullanilan[$b->hizmet_id] ?? 0));
                if ($kalan > 0) {
                    $hizmetler[] = ['hizmet_id' => (int) $b->hizmet_id, 'ad' => $b->hizmet_adi, 'kalan' => $kalan, 'toplam' => (int) $ap->seans_sayisi,
                        'sure' => (int) ($b->sure ?? 0) ?: ($salonSure[$b->hizmet_id] ?? 30)];
                }
            }
            if ($hizmetler) {
                $sonuc[] = [
                    'tur' => 'paket', 'adi' => $ap->paket_adi, 'adisyon_paket_id' => (int) $ap->id, 'adisyon_hizmet_id' => null,
                    'satis_tarihi' => $ap->tarih, 'paket_sure' => ($ap->paket_sure ?? null) ? (int) $ap->paket_sure : null,
                    'hizmetler' => $hizmetler,
                ];
            }
        }

        return $sonuc;
    }

    /** Salonun hizmet sureleri (hizmet basina son kayit) — paket bolgesi suresi yoksa yedek. */
    private function salonHizmetSureleri(int $salonId): array
    {
        $son = DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->groupBy('hizmet_id')->selectRaw('MAX(id) as id')->pluck('id');

        return DB::table('salon_sunulan_hizmetler')->whereIn('id', $son)->whereNotNull('sure_dk')->pluck('sure_dk', 'hizmet_id')
            ->map(fn ($x) => (int) $x)->all();
    }

    // ─────────────────────────────────────────────────────────────

    /** Hizmet basina EN SON salon_sunulan_hizmetler kaydi; santral haric; duzenlemede randevunun pasif hizmetleri dahil. */
    private function hizmetler(int $salonId, ?int $randevuId): array
    {
        $randevuHizmetleri = $randevuId
            ? DB::table('randevu_hizmetler')->where('randevu_id', $randevuId)->whereNotNull('hizmet_id')->pluck('hizmet_id')->all()
            : [];
        $sonIdler = DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)
            ->where(fn ($w) => $w->where('santral_hizmeti', '!=', 1)->orWhereNull('santral_hizmeti'))
            ->where(fn ($w) => $w->where('aktif', 1)->when($randevuHizmetleri, fn ($x) => $x->orWhereIn('hizmet_id', $randevuHizmetleri)))
            ->groupBy('hizmet_id')->selectRaw('MAX(id) as id')->pluck('id');

        return DB::table('salon_sunulan_hizmetler as ssh')->join('hizmetler as h', 'h.id', '=', 'ssh.hizmet_id')
            ->whereIn('ssh.id', $sonIdler)->orderBy('h.hizmet_adi')
            ->get(['ssh.hizmet_id', 'h.hizmet_adi', 'ssh.sure_dk', 'ssh.baslangic_fiyat', 'ssh.hizmet_kategori_id', 'ssh.aktif'])
            ->map(fn ($h) => [
                'hizmet_id'   => (int) $h->hizmet_id,
                'ad'          => $h->hizmet_adi,
                'sure_dk'     => $h->sure_dk !== null ? (int) $h->sure_dk : null,
                'fiyat'       => $h->baslangic_fiyat !== null ? (float) $h->baslangic_fiyat : null,
                'kategori_id' => $h->hizmet_kategori_id ? (int) $h->hizmet_kategori_id : null,
                'aktif'       => (bool) $h->aktif,
            ])->all();
    }

    private function haritalar(int $salonId): array
    {
        $grupla = function ($satirlar, string $anahtar, string $deger): array {
            $m = [];
            foreach ($satirlar as $r) {
                $m[(int) $r->{$anahtar}][] = (int) $r->{$deger};
            }

            return $m;
        };

        $ph = DB::table('personel_sunulan_hizmetler as psh')->join('salon_personelleri as sp', 'psh.personel_id', '=', 'sp.id')
            ->where('sp.salon_id', $salonId)->get(['psh.personel_id', 'psh.hizmet_id']);
        $pm = $grupla($ph, 'personel_id', 'hizmet_id');
        // Hicbir personele atanmamis hizmet → tum aktif personeller yapabilir.
        $atanmamis = array_values(array_diff(
            DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('aktif', 1)->pluck('hizmet_id')->map(fn ($x) => (int) $x)->unique()->all(),
            $ph->pluck('hizmet_id')->map(fn ($x) => (int) $x)->unique()->all(),
        ));
        if ($atanmamis) {
            foreach (DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)->pluck('id') as $pid) {
                $pm[(int) $pid] = array_values(array_unique(array_merge($pm[(int) $pid] ?? [], $atanmamis)));
            }
        }

        $odaIdler = DB::table('odalar')->where('salon_id', $salonId)->pluck('id')->all();

        return [
            'personel_hizmet_map' => (object) $pm,
            'cihaz_hizmet_map'    => (object) $grupla(DB::table('cihaz_sunulan_hizmetler as c')->join('cihazlar as ci', 'c.cihaz_id', '=', 'ci.id')
                ->where('ci.salon_id', $salonId)->get(['c.cihaz_id', 'c.hizmet_id']), 'cihaz_id', 'hizmet_id'),
            'oda_hizmet_map'      => (object) $grupla(DB::table('oda_sunulan_hizmetler')->where('salon_id', $salonId)->get(['oda_id', 'hizmet_id']), 'oda_id', 'hizmet_id'),
            'oda_personel_map'    => (object) ($odaIdler && Schema::hasTable('oda_personelleri')
                ? $grupla(DB::table('oda_personelleri')->whereIn('oda_id', $odaIdler)->get(['oda_id', 'personel_id']), 'oda_id', 'personel_id')
                : []),
        ];
    }

    /**
     * Duzenleme icin mevcut randevu (gonderilecek govdeyle ayni sekil).
     * 'birlestir' saklanmaz → bir sonraki ana satir AYNI saatte basliyorsa bu satir birlestir='1'.
     */
    private function randevuOnDoldur(int $salonId, int $randevuId, bool $telGor): array
    {
        $r = DB::table('randevular')->where('id', $randevuId)->first();
        if (! $r || (int) $r->salon_id !== $salonId || (int) $r->user_id === Randevular::KAPALI_SAAT_USER_ID) {
            throw new BulunamadiException('Randevu bulunamadı');
        }
        $kalemler = DB::table('randevu_hizmetler')->where('randevu_id', $randevuId)->orderBy('saat')->orderBy('id')->get();
        $ana = $kalemler->filter(fn ($k) => ! $k->yardimci_personel)->values();

        $hizmetler = [];
        foreach ($ana as $i => $k) {
            $sonraki = $ana[$i + 1] ?? null;
            $hizmetler[] = [
                'rh_id' => (int) $k->id, 'hizmet_id' => (int) $k->hizmet_id,
                'personel_id' => $k->personel_id ? (int) $k->personel_id : null,
                'oda_id' => $k->oda_id ? (int) $k->oda_id : null, 'cihaz_id' => $k->cihaz_id ? (int) $k->cihaz_id : null,
                'sure_dk' => (int) $k->sure_dk, 'fiyat' => $k->fiyat !== null ? (float) $k->fiyat : 0,
                'birlestir' => $sonraki && substr((string) $sonraki->saat, 0, 5) === substr((string) $k->saat, 0, 5) ? '1' : '',
                'dusum_miktari' => $k->dusum_miktari ? (int) $k->dusum_miktari : null,
            ];
        }
        $yardimcilar = [];
        foreach ($kalemler->filter(fn ($k) => $k->yardimci_personel) as $y) {
            $idx = $ana->search(fn ($a) => (int) $a->hizmet_id === (int) $y->hizmet_id && $a->saat == $y->saat);
            if ($idx !== false) {
                $yardimcilar[] = ['randevuhizmetid' => (int) $y->hizmet_id, 'index' => $idx, 'yardimcipersonel' => ['id' => (int) $y->personel_id]];
            }
        }
        $u = DB::table('users')->where('id', $r->user_id)->first(['name', 'cep_telefon']);

        return [
            'randevu_id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'musteri_ad' => $u->name ?? null,
            'telefon' => $u ? ($telGor ? $u->cep_telefon : YetkiSabitleri::telefonMaskele((string) $u->cep_telefon)) : null,
            'randevu_tarihi' => date('Y-m-d', strtotime($r->tarih)), 'randevu_saati' => substr((string) $r->saat, 0, 5),
            'durum' => (int) $r->durum, 'notlar' => $r->personel_notu,
            'hizmetler' => $hizmetler, 'yardimcipersoneller' => $yardimcilar,
        ];
    }
}
