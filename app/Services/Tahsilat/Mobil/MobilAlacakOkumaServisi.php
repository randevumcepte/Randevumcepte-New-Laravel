<?php

namespace App\Services\Tahsilat\Mobil;

use App\Domain\Tahsilat\MobilTahsilatHesabi as Hesap;
use App\Services\Paket\PaketYukseltServisi;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Eski mobil alacak ve senet OKUMALARI: alacaklar, alacaklar-v2, tum-alacaklar, senetler. Filtre, sayfa boyu ve tutar
 * eski koddan satir satir. Ham model dokumu yerine ekranin okudugu alanlar doner: yetkili sifresi, personel sifresi,
 * maas ve salon anahtarlari cikmaz. Tarih alanlari ham kolon degeridir (eski cevapla ayni bicim).
 */
class MobilAlacakOkumaServisi
{
    private const SAYFA = 10;

    /** Eski tum_alacaklar'in kalem basina dondurdugu kolonlar; prim ve diger kolonlar cikmaz. */
    private const KALEM_ORTAK = ['id', 'adisyon_id', 'fiyat', 'personel_id', 'taksitli_tahsilat_id', 'senet_id', 'indirim_tutari', 'hediye'];

    private const KALEM_EK = [
        'hizmet' => ['hizmet_id', 'islem_tarihi', 'islem_saati', 'sure', 'geldi', 'cihaz_id', 'oda_id'],
        'urun'   => ['urun_id', 'adet', 'aciklama'],
        'paket'  => ['paket_id', 'seans_araligi', 'baslangic_tarihi'],
    ];

    public function alacaklar(int $salonId, ?string $ara, int $sayfa): LengthAwarePaginator
    {
        return DB::table('alacaklar')->join('users', 'alacaklar.user_id', '=', 'users.id')
            ->select(
                'alacaklar.id as id',
                'users.name as musteri',
                'alacaklar.planlanan_odeme_tarihi as planlanan_odeme_tarihi',
                DB::raw('FORMAT(alacaklar.tutar,2,"tr_TR") as tutar'),
                DB::raw('DATE_FORMAT(alacaklar.created_at, "%d.%m.%Y %H:%i") as olusturulma')
            )
            ->where('users.name', 'like', '%'.$ara.'%')
            ->where('planlanan_odeme_tarihi', '>=', date('Y-m-01'))
            ->where('planlanan_odeme_tarihi', '<=', date('Y-m-d'))
            ->where('alacaklar.salon_id', $salonId)
            ->paginate(self::SAYFA, ['*'], 'page', $sayfa);
    }

    public function alacaklarV2(int $salonId, ?string $ara, int $sayfa): LengthAwarePaginator
    {
        $bugun = date('Y-m-d');
        $s = DB::table('alacaklar')->join('users', 'alacaklar.user_id', '=', 'users.id')
            ->select('alacaklar.id', 'alacaklar.user_id', 'alacaklar.adisyon_id', 'alacaklar.tutar', 'alacaklar.aciklama',
                'alacaklar.planlanan_odeme_tarihi', 'alacaklar.created_at', 'users.name as musteri')
            ->where('alacaklar.salon_id', $salonId)
            ->where('users.name', 'like', '%'.$ara.'%')
            ->where('planlanan_odeme_tarihi', '<=', $bugun)
            ->whereRaw('NOT EXISTS (SELECT 1 FROM taksit_vadeleri tv WHERE tv.id = alacaklar.taksit_vade_id AND tv.odendi = 1)')
            ->whereRaw('NOT EXISTS (SELECT 1 FROM senet_vadeleri sv WHERE sv.id = alacaklar.senet_vade_id AND sv.odendi = 1)')
            ->where('alacaklar.tutar', '>', 0)
            ->orderBy('planlanan_odeme_tarihi', 'asc')
            ->paginate(self::SAYFA, ['*'], 'page', $sayfa);

        $adlar = $this->adisyonKalemAdlari($s->getCollection()->pluck('adisyon_id')->filter()->unique()->all());
        $s->getCollection()->transform(fn ($a) => [
            'id' => $a->id,
            'user_id' => $a->user_id,
            'adisyon_id' => $a->adisyon_id,
            'musteri' => $a->musteri ?? '',
            'kalem' => Hesap::alacakBasligi($adlar[$a->adisyon_id] ?? [], $a->aciklama),
            'tutar' => number_format((float) $a->tutar, 2, ',', '.'),
            'planlanan_odeme_tarihi' => $a->planlanan_odeme_tarihi,
            'olusturulma' => $a->created_at ? date('d.m.Y H:i', strtotime($a->created_at)) : '',
            'vadesi_gecmis' => $a->planlanan_odeme_tarihi < $bugun,
        ]);

        return $s;
    }

    /** Adisyon basina hizmet, paket, urun sirasiyla ad listesi. */
    private function adisyonKalemAdlari(array $adisyonIdleri): array
    {
        $adlar = [];
        foreach ([
            ['adisyon_hizmetler', 'hizmetler', 'hizmet_id', 'hizmet_adi'],
            ['adisyon_paketler', 'paketler', 'paket_id', 'paket_adi'],
            ['adisyon_urunler', 'urunler', 'urun_id', 'urun_adi'],
        ] as [$kalem, $katalog, $fk, $kolon]) {
            $satirlar = DB::table($kalem.' as k')->join($katalog.' as c', 'c.id', '=', 'k.'.$fk)
                ->whereIn('k.adisyon_id', $adisyonIdleri)->orderBy('k.id')->get(['k.adisyon_id', 'c.'.$kolon.' as ad']);
            foreach ($satirlar as $r) {
                $adlar[$r->adisyon_id][] = $r->ad;
            }
        }

        return $adlar;
    }

    public function tumAlacaklar(int $salonId, mixed $musteriId, mixed $adisyonId): array
    {
        $adisyonlar = DB::table('adisyonlar')->where('salon_id', $salonId)->where('user_id', $musteriId)
            ->when($adisyonId != 'null' && $adisyonId != '', fn (Builder $q) => $q->where('id', $adisyonId))
            ->pluck('id')->all();

        return [
            'senet'         => $this->planlar('senetler', $salonId, $musteriId, 'senet_id', 'senet_vadeleri'),
            'taksit'        => $this->planlar('taksitli_tahsilatlar', $salonId, $musteriId, 'taksitli_tahsilat_id', 'taksit_vadeleri'),
            'adisyon_hizmet' => $this->acikKalemler($adisyonlar, 'adisyon_hizmetler', 'tahsilat_hizmetler', 'adisyon_hizmet_id', 'hizmetler', 'hizmet_id', 'hizmet_adi', 'hizmet'),
            'adisyon_urun'  => $this->acikKalemler($adisyonlar, 'adisyon_urunler', 'tahsilat_urunler', 'adisyon_urun_id', 'urunler', 'urun_id', 'urun_adi', 'urun'),
            'adisyon_paket' => $this->yukseltmeKaynakli($this->acikKalemler($adisyonlar, 'adisyon_paketler', 'tahsilat_paketler', 'adisyon_paket_id', 'paketler', 'paket_id', 'paket_adi', 'paket')),
        ];
    }

    /**
     * Paket kalemine "Yukseltildi (eski paket)" kaynagi: web adisyon cevabiyla ayni alan (`yukseltme_kaynak`),
     * ayni ortak sorgu (PaketYukseltServisi::kaynaklar, toplu). Yukseltilmemis kalemde null.
     *
     * @param  list<array<string,mixed>>  $paketler
     * @return list<array<string,mixed>>
     */
    private function yukseltmeKaynakli(array $paketler): array
    {
        $kaynak = PaketYukseltServisi::kaynaklar(array_map(fn ($p) => (int) $p['id'], $paketler));

        return array_map(fn ($p) => $p + ['yukseltme_kaynak' => $kaynak[(int) $p['id']] ?? null], $paketler);
    }

    /** Odenmemis (ya da hediye) ve plana baglanmamis kalemler; fiyat alani KALAN tutardir. */
    private function acikKalemler(array $adisyonlar, string $tablo, string $odemeTablosu, string $fk, string $katalog, string $katalogFk, string $adKolonu, string $alan): array
    {
        $satirlar = [];
        $alanlar = [...self::KALEM_ORTAK, ...self::KALEM_EK[$alan]];
        foreach ($adisyonlar as $adisyonId) {
            foreach (DB::table($tablo)->where('adisyon_id', $adisyonId)->get() as $k) {
                $odenen = DB::table($odemeTablosu)->where($fk, $k->id)->sum('tutar');
                $kalan = Hesap::kalemKalani($k->fiyat, $odenen, $k->indirim_tutari);
                if (! Hesap::alacakGorunurMu($kalan, $k->hediye, $k->senet_id, $k->taksitli_tahsilat_id)) {
                    continue;
                }
                $satir = array_intersect_key((array) $k, array_flip($alanlar));
                $satir['fiyat'] = $kalan;
                $satir['dogrulama_kodu'] = null;
                $satir['personel'] = $this->personel($k->personel_id ?? null);
                $satir[$alan] = $this->katalog($katalog, $k->{$katalogFk} ?? null, $adKolonu);
                if ($alan === 'hizmet') {
                    $satir['cihaz'] = $k->cihaz_id ? DB::table('cihazlar')->where('id', $k->cihaz_id)->first(['id', 'cihaz_adi']) : null;
                    $satir['oda'] = null;
                }
                if ($alan === 'paket') {
                    $satir['seanslar'] = DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $k->id)->get(['id', 'seans_tarih', 'seans_saat', 'geldi', 'hizmet_id', 'seans_no', 'randevu_id', 'personel_id'])->map(fn ($r) => (array) $r)->all();
                    $satir['urun'] = null;
                }
                $satirlar[] = $satir;
            }
        }

        return $satirlar;
    }

    private function personel(mixed $id): ?object
    {
        return $id ? DB::table('salon_personelleri')->where('id', $id)->first(['id', 'personel_adi']) : null;
    }

    private function katalog(string $tablo, mixed $id, string $kolon): ?object
    {
        return $id ? DB::table($tablo)->where('id', $id)->first(['id', $kolon]) : null;
    }

    /** @return list<array<string,mixed>> */
    private function planlar(string $tablo, int $salonId, mixed $musteriId, string $planKolonu, string $vadeTablosu): array
    {
        $planlar = DB::table($tablo)->where('salon_id', $salonId)->where('user_id', $musteriId)->get();

        return $planlar->map(fn ($p) => $this->planSatiri($p, $planKolonu, $vadeTablosu))->all();
    }

    private function planSatiri(object $p, string $planKolonu, string $vadeTablosu): array
    {
        $ad = fn (string $kalem, string $katalog, string $fk, string $kolon) => DB::table($kalem.' as k')->leftJoin($katalog.' as c', 'c.id', '=', 'k.'.$fk)
            ->where('k.'.$planKolonu, $p->id)->orderBy('k.id')->pluck('c.'.$kolon)->all();

        return (array) $p + [
            'kalem_ozeti' => Hesap::kalemOzeti(
                $ad('adisyon_hizmetler', 'hizmetler', 'hizmet_id', 'hizmet_adi'),
                $ad('adisyon_urunler', 'urunler', 'urun_id', 'urun_adi'),
                $ad('adisyon_paketler', 'paketler', 'paket_id', 'paket_adi'),
            ),
            'musteri' => DB::table('users')->where('id', $p->user_id)->first(['id', 'name', 'adres', 'tc_kimlik_no']),
            'adisyon' => $p->adisyon_id ? DB::table('adisyonlar')->where('id', $p->adisyon_id)->first(['id', 'user_id', 'salon_id', 'tarih', 'olusturan_id', 'created_at', 'updated_at']) : null,
            'vadeler' => DB::table($vadeTablosu.' as v')->leftJoin('odeme_yontemleri as o', 'o.id', '=', 'v.odeme_yontemi_id')
                ->where('v.'.$planKolonu, $p->id)
                ->get(['v.*', 'o.odeme_yontemi as odeme_turu'])
                ->map(function ($v) {
                    $s = (array) $v;
                    $s['dogrulama_kodu'] = null;
                    $s['odeme_turu'] = $v->odeme_yontemi_id ? ['id' => $v->odeme_yontemi_id, 'odeme_yontemi' => $v->odeme_turu] : null;

                    return $s;
                })->all(),
        ];
    }

    /** Eski senetler: durum Tumu, Kapali, Acik, Odenmemis; baska deger bos cevap. Sayfa basi 10. */
    public function senetler(int $salonId, ?string $ara, ?string $durum, int $sayfa): ?array
    {
        if (! in_array($durum, ['Tümü', 'Kapalı', 'Açık', 'Ödenmemiş'], true)) {
            return null;
        }

        $vade = fn () => DB::table('senet_vadeleri as v')->selectRaw('1')->whereColumn('v.senet_id', 'senetler.id');
        $q = DB::table('senetler')->where('salon_id', $salonId)
            ->whereExists(fn ($w) => $w->from('users')->selectRaw('1')->whereColumn('users.id', 'senetler.user_id')->where('name', 'like', '%'.$ara.'%'))
            ->whereExists($vade());
        if ($durum === 'Kapalı') {
            $q->whereNotExists($vade()->where('v.odendi', '!=', 1));
        } elseif ($durum === 'Açık') {
            $q->whereExists($vade()->where('v.odendi', 0));
        } elseif ($durum === 'Ödenmemiş') {
            $q->whereExists($vade()->where('v.odendi', 0)->where('v.vade_tarih', '<=', date('Y-m-d')));
        }

        $s = $q->paginate(self::SAYFA, ['*'], 'page', $sayfa);
        $s->getCollection()->transform(fn ($p) => $this->planSatiri($p, 'senet_id', 'senet_vadeleri'));

        return $s->toArray();
    }
}
