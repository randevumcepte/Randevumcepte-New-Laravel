<?php

namespace App\Services\Kasa;

use App\Domain\Rapor\RaporDonemi;
use App\Domain\Satis\KalemTuru;
use App\Exceptions\BulunamadiException;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Kasa Raporu (OKUMA) — eski isletmeyonetim/kasadefteri (kasa_raporu_getir,
 * kasaGelirDetay, devredenAylar) ve mobil v1 kasaraporu / tahsilatraporu / masrafraporu /
 * devredenAylar uclarinin TEK v2 karsiligi.
 *
 * Model (eskiyle ayni): ayri kasa defteri YOK — gelir = tahsilatlar (manuel para girisi
 * dahil, odeme_tarihi), gider = masraflar (para cekme, personel gideri / odemesi dahil, tarih).
 * Duzeltilenler: liste HTML yerine JSON + sunucu sayfalama/arama (ListeIstegi); faturasiz
 * gizleme tek SQL ifadesi (KasaTutar), aylik ozet de ona uyar; "Kasa" (harcayansiz)
 * masraflar mobilde de gorunur; ay sonu gunu kaybolmaz; null odeme yontemi / silinmis
 * personel sayfayi 500'e dusurmez. Hicbir sey YAZMAZ.
 */
class KasaRaporServisi
{
    public const GELIR_SIRALANABILIR = ['odeme_tarihi', 'musteri', 'tahsil_eden', 'odeme_yontemi', 'tutar'];

    public const GIDER_SIRALANABILIR = ['tarih', 'harcayan', 'odeme_yontemi', 'tutar'];

    public const AYLAR = ['OCAK', 'ŞUBAT', 'MART', 'NİSAN', 'MAYIS', 'HAZİRAN', 'TEMMUZ', 'AĞUSTOS', 'EYLÜL', 'EKİM', 'KASIM', 'ARALIK'];

    public function __construct(private KasaTutar $tutar) {}

    /**
     * Kartlar: donem gelir / gider / net + tum zamanlar "Toplam Kazanc" (filtresiz, eskiyle ayni).
     * Banka filtresi yalniz geliri suzer (masraflarda banka tutulmaz); o zaman net verilmez (null): suzulmus gelirden
     * tum giderleri dusmek yaniltici olur.
     */
    public function ozet(int $salonId, RaporDonemi $d, ?int $odemeYontemiId, ?int $bankaId = null): array
    {
        $faturali = $this->tutar->faturaliMi($salonId);
        $gelir = (float) $this->gelirSorgusu($salonId, $faturali, $d, $odemeYontemiId, $bankaId)->sum(DB::raw(KasaTutar::tutarSql($faturali)));
        $gider = (float) $this->giderSorgusu($salonId, $d, $odemeYontemiId)->sum('m.tutar');
        $tumGelir = (float) $this->gelirSorgusu($salonId, $faturali)->sum(DB::raw(KasaTutar::tutarSql($faturali)));
        $tumGider = (float) $this->giderSorgusu($salonId)->sum('m.tutar');

        return [
            'gelir'          => round($gelir, 2),
            'gider'          => round($gider, 2),
            'net'            => $bankaId ? null : round($gelir - $gider, 2),
            'toplam_kazanc'  => round($tumGelir - $tumGider, 2),
            'faturasiz_gizle' => $faturali,
            'donem'          => $d->dizi(),
        ];
    }

    /**
     * Gelirler tablosu (sayfali). sil_turu: para_girisi | bagimsiz | null — kasadan
     * silinebilen kayitlar (satis tahsilati Satis ekranindan iade edilir).
     *
     * @param  array  $o  ListeIstegi::dizi()
     */
    public function gelirler(int $salonId, RaporDonemi $d, ?int $odemeYontemiId, array $o, bool $silebilir, ?int $bankaId = null): array
    {
        $faturali = $this->tutar->faturaliMi($salonId);
        $q = $this->gelirSorgusu($salonId, $faturali, $d, $odemeYontemiId, $bankaId)
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 't.olusturan_id')
            ->leftJoin('odeme_yontemleri as oy', 'oy.id', '=', 't.odeme_yontemi_id')
            ->leftJoin('bankalar as b', 'b.id', '=', 't.banka_id')
            ->when(ListeIstegi::like($o['ara'] ?? null), fn ($q, $like) => $q->where(fn ($w) => $w
                ->where('u.name', 'like', $like)->orWhere('p.personel_adi', 'like', $like)
                ->orWhere('t.notlar', 'like', $like)->orWhere('oy.odeme_yontemi', 'like', $like)));

        $toplam = (clone $q)->count();
        $siralama = ['odeme_tarihi' => 't.odeme_tarihi', 'musteri' => 'u.name', 'tahsil_eden' => 'p.personel_adi',
            'odeme_yontemi' => 'oy.odeme_yontemi', 'tutar' => 'tutar'];

        $data = $q->orderBy($siralama[$o['sirala'] ?? ''] ?? 't.odeme_tarihi', $o['yon'] ?? 'desc')->orderByDesc('t.id')
            ->forPage($o['page'], $o['per_page'])
            ->get([
                't.id', 't.odeme_tarihi', 't.notlar', 't.adisyon_id', 't.para_girisi',
                'u.name as musteri', 'p.personel_adi as tahsil_eden', 'oy.odeme_yontemi', 'b.banka',
                DB::raw(KasaTutar::tutarSql($faturali).' as tutar'),
                DB::raw(KasaTutar::bagsizSql().' as bagsiz'),
            ])
            ->map(function ($t) use ($silebilir) {
                $bagsiz = (bool) $t->bagsiz;
                $silTuru = $t->para_girisi ? 'para_girisi' : (! $t->adisyon_id || $bagsiz ? 'bagimsiz' : null);

                return [
                    'id'            => (int) $t->id,
                    'odeme_tarihi'  => $t->odeme_tarihi,
                    'musteri'       => $t->para_girisi ? null : $t->musteri,
                    'tahsil_eden'   => $t->tahsil_eden,
                    'notlar'        => $t->notlar,
                    'odeme_yontemi' => $t->odeme_yontemi,
                    'banka'         => $t->banka,
                    'tutar'         => round((float) $t->tutar, 2),
                    'para_girisi'   => (bool) $t->para_girisi,
                    'bagsiz'        => $bagsiz,
                    'adisyon_id'    => $t->adisyon_id ? (int) $t->adisyon_id : null,
                    'sil_turu'      => $silebilir ? $silTuru : null,
                ];
            })->all();

        return ['data' => $data] + ListeIstegi::meta($o['page'], $o['per_page'], $toplam);
    }

    /** Giderler tablosu (sayfali). rozet: personel_gideri | personel_odemesi | isletme. */
    public function giderler(int $salonId, RaporDonemi $d, ?int $odemeYontemiId, array $o, bool $duzenlenebilir): array
    {
        $q = $this->giderSorgusu($salonId, $d, $odemeYontemiId)
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 'm.harcayan_id')
            ->leftJoin('masraf_kategorileri as mk', 'mk.id', '=', 'm.masraf_kategori_id')
            ->leftJoin('odeme_yontemleri as oy', 'oy.id', '=', 'm.odeme_yontemi_id')
            ->when(ListeIstegi::like($o['ara'] ?? null), fn ($q, $like) => $q->where(fn ($w) => $w
                ->where('p.personel_adi', 'like', $like)->orWhere('m.aciklama', 'like', $like)
                ->orWhere('m.notlar', 'like', $like)->orWhere('mk.kategori', 'like', $like)->orWhere('oy.odeme_yontemi', 'like', $like)));

        $toplam = (clone $q)->count();
        $siralama = ['tarih' => 'm.tarih', 'harcayan' => 'p.personel_adi', 'odeme_yontemi' => 'oy.odeme_yontemi', 'tutar' => 'm.tutar'];

        $data = $q->orderBy($siralama[$o['sirala'] ?? ''] ?? 'm.tarih', $o['yon'] ?? 'desc')->orderByDesc('m.id')
            ->forPage($o['page'], $o['per_page'])
            ->get(['m.id', 'm.tarih', 'm.tutar', 'm.aciklama', 'm.notlar', 'm.harcayan_id', 'm.personel_gideri',
                'm.personel_maas_odemesi_id', 'p.personel_adi as harcayan', 'mk.kategori', 'oy.odeme_yontemi'])
            ->map(fn ($m) => [
                'id'            => (int) $m->id,
                'tarih'         => $m->tarih,
                'rozet'         => $m->personel_gideri ? 'personel_gideri' : ($m->personel_maas_odemesi_id ? 'personel_odemesi' : 'isletme'),
                'kategori'      => $m->kategori,
            ] + self::masrafMetni($m) + [
                'odeme_yontemi' => $m->odeme_yontemi,
                'tutar'         => round((float) $m->tutar, 2),
                'duzenlenebilir' => $duzenlenebilir && ! $m->personel_maas_odemesi_id,
            ])->all();

        return ['data' => $data] + ListeIstegi::meta($o['page'], $o['per_page'], $toplam);
    }

    /**
     * Masraf satirinin gorunen metni (kasa + gun sonu ortak): harcayansiz → "Kasa";
     * aciklama bossa kategori, o da yoksa not (eski Para Cek notu 'notlar'a yaziyordu).
     *
     * @return array{harcayan:string, aciklama:?string}
     */
    public static function masrafMetni(object $m): array
    {
        return [
            'harcayan' => $m->harcayan_id ? ($m->harcayan ?: '-') : 'Kasa',
            'aciklama' => trim((string) $m->aciklama) !== '' ? $m->aciklama : ($m->kategori ?: $m->notlar),
        ];
    }

    /** Gelir detayi (satira tiklaninca): baslik + odenen kalemler. Baska salonun kaydi 404. */
    public function gelirDetay(int $salonId, int $tahsilatId): array
    {
        $t = DB::table('tahsilatlar as t')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('salon_personelleri as po', 'po.id', '=', 't.olusturan_id')
            ->leftJoin('salon_personelleri as ps', 'ps.id', '=', 't.personel_id')
            ->leftJoin('odeme_yontemleri as oy', 'oy.id', '=', 't.odeme_yontemi_id')
            ->leftJoin('bankalar as b', 'b.id', '=', 't.banka_id')
            ->leftJoin('adisyonlar as a', 'a.id', '=', 't.adisyon_id')
            ->where('t.id', $tahsilatId)->where('t.salon_id', $salonId)
            ->first(['t.*', 'u.name as musteri', 'po.personel_adi as tahsil_eden', 'ps.personel_adi as satici',
                'oy.odeme_yontemi as yontem', 'b.banka as banka_adi', 'a.id as adisyon_var', 'a.harici']);
        if (! $t) {
            throw new BulunamadiException('Kayıt bulunamadı');
        }

        $kalemler = [];
        foreach (KalemTuru::cases() as $tur) {
            $rows = DB::table($tur->tahsilatTablosu().' as x')
                ->join($tur->kalemTablosu().' as k', 'k.id', '=', 'x.'.$tur->tahsilatFk())
                ->leftJoin($tur->katalogTablosu().' as c', 'c.id', '=', 'k.'.$tur->katalogFk())
                ->leftJoin('salon_personelleri as p', 'p.id', '=', 'k.personel_id')
                ->where('x.tahsilat_id', $tahsilatId)
                ->get(['x.tutar', 'c.'.$tur->adKolonu().' as ad', 'p.personel_adi as personel',
                    DB::raw($tur === KalemTuru::Urun ? 'COALESCE(k.adet,1) as adet' : '1 as adet')]);
            foreach ($rows as $r) {
                $kalemler[] = ['tip' => $tur->etiket(), 'ad' => $r->ad ?: '-', 'personel' => $r->personel,
                    'adet' => (int) $r->adet, 'tutar' => round((float) $r->tutar, 2)];
            }
        }

        // tahsilatlar'da para_cikisi kolonu YOK (eski 'Kasa Para Çıkışı' dalı ölüydü; para çekme masraftır).
        $tip = $t->para_girisi ? 'Kasa Para Girişi' : ($t->adisyon_id ? 'Satış Tahsilatı' : 'Bağımsız Tahsilat');

        return [
            'id'            => (int) $t->id,
            'tip'           => $tip,
            'odeme_tarihi'  => $t->odeme_tarihi,
            'musteri'       => $t->musteri,
            'tahsil_eden'   => $t->tahsil_eden,
            'satici'        => $t->satici,
            'odeme_yontemi' => $t->yontem,
            'banka'         => $t->banka_adi,
            'notlar'        => $t->notlar,
            'tutar'         => round((float) $t->tutar, 2),
            'adisyon_id'    => $t->adisyon_var ? (int) $t->adisyon_var : null,
            'harici'        => (bool) $t->harici,
            'kalemler'      => $kalemler,
        ];
    }

    /** "Devreden Aylar": yilin 12 ayi icin gelir / gider / net (2 GROUP BY sorgusu; eskisi 24 SUM). */
    public function aylik(int $salonId, int $yil): array
    {
        $faturali = $this->tutar->faturaliMi($salonId);
        $aralik = RaporDonemi::coz('ozel', sprintf('%04d-01-01', $yil), sprintf('%04d-12-31', $yil));
        $gelir = $this->gelirSorgusu($salonId, $faturali, $aralik)->groupByRaw('MONTH(t.odeme_tarihi)')
            ->selectRaw('MONTH(t.odeme_tarihi) as ay, SUM('.KasaTutar::tutarSql($faturali).') as toplam')->pluck('toplam', 'ay');
        $gider = $this->giderSorgusu($salonId, $aralik)->groupByRaw('MONTH(m.tarih)')
            ->selectRaw('MONTH(m.tarih) as ay, SUM(m.tutar) as toplam')->pluck('toplam', 'ay');

        $aylar = [];
        foreach (self::AYLAR as $i => $ad) {
            $g = round((float) ($gelir[$i + 1] ?? 0), 2);
            $c = round((float) ($gider[$i + 1] ?? 0), 2);
            $aylar[] = ['ay' => $i + 1, 'ay_adi' => $ad, 'yil' => $yil, 'gelir' => $g, 'gider' => $c, 'net' => round($g - $c, 2)];
        }

        return ['yil' => $yil, 'aylar' => $aylar, 'toplam_net' => round(array_sum(array_column($aylar, 'net')), 2)];
    }

    /** Salonun tahsilatlari (donem/odeme yontemi/banka opsiyonel; faturasiz gizlemede yalniz faturali kisim). */
    public function gelirSorgusu(int $salonId, bool $faturali, ?RaporDonemi $d = null, ?int $odemeYontemiId = null, ?int $bankaId = null): Builder
    {
        return DB::table('tahsilatlar as t')
            ->where('t.salon_id', $salonId)
            ->when($d, fn ($q) => $q->whereBetween('t.odeme_tarihi', [$d->basSaat(), $d->bitSaat()]))
            ->when($odemeYontemiId, fn ($q) => $q->where('t.odeme_yontemi_id', $odemeYontemiId))
            ->when($bankaId, fn ($q) => $q->where('t.banka_id', $bankaId))
            ->when($faturali, fn ($q) => $q->whereRaw(KasaTutar::faturaliSql().' > 0'));
    }

    /** Salonun masraflari (donem/odeme yontemi opsiyonel). */
    public function giderSorgusu(int $salonId, ?RaporDonemi $d = null, ?int $odemeYontemiId = null): Builder
    {
        return DB::table('masraflar as m')
            ->where('m.salon_id', $salonId)
            ->when($d, fn ($q) => $q->whereBetween('m.tarih', [$d->basSaat(), $d->bitSaat()]))
            ->when($odemeYontemiId, fn ($q) => $q->where('m.odeme_yontemi_id', $odemeYontemiId));
    }
}
