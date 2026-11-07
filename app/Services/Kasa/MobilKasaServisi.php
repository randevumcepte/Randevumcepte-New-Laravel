<?php

namespace App\Services\Kasa;

use App\Domain\Rapor\MobilKasaHesabi;
use App\Domain\Satis\KalemTuru;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Mobil kasa okuma (mobil bayragi ile) — eski ApiController kasaraporu, tahsilatraporu, masrafraporu, gunSonuRaporu,
 * devredenAylar kapsam ve filtreleri BIREBIR, mobilin gordugu tutar degismesin diye. Web bu servisi kullanmaz.
 *
 * Fark yalniz sizinti: iliskilerden sifre, maas, telefon gibi alanlar gitmez; ekranin okudugu alanlar gider.
 * $f: ['tarih1' => ?string, 'tarih2' => ?string, 'odeme' => ?int]
 */
class MobilKasaServisi
{
    private const SAYFA_BOYU = 5;

    public function __construct(private KasaTutar $tutar) {}

    public function ozet(int $salonId, array $f): array
    {
        $gizli = $this->tutar->faturaliMi($salonId);
        $tahsilat = $this->tahsilatlar($salonId, $f);
        if ($gizli) {
            $harita = $this->faturaliHarita($salonId);
            $donemGelir = MobilKasaHesabi::faturaliGelir($tahsilat->whereIn('id', array_keys($harita) ?: [0])->pluck('id'), $harita);
            $tumGelir = array_sum($harita);
        } else {
            $donemGelir = $tahsilat->sum('tutar');
            $tumGelir = DB::table('tahsilatlar')->where('salon_id', $salonId)->sum('tutar');
        }

        $donemGider = DB::table('masraflar')->where('salon_id', $salonId)
            ->when($f['tarih1'] !== null && $f['tarih2'] !== null, fn ($q) => $q->whereDate('tarih', '>=', $f['tarih1'])->whereDate('tarih', '<=', $f['tarih2']))
            ->when($f['odeme'], fn ($q) => $q->where('odeme_yontemi_id', $f['odeme']))
            ->sum('tutar');
        $tumGider = DB::table('masraflar')->where('salon_id', $salonId)->sum('tutar');

        return MobilKasaHesabi::ozet($donemGelir, $donemGider, $tumGelir, $tumGider);
    }

    /** Sayfa basina 5 satir; tutar faturasiz gizlemede faturali kisimdir. */
    public function tahsilatSayfasi(int $salonId, array $f, int $sayfa): array
    {
        $harita = $this->tutar->faturaliMi($salonId) ? $this->faturaliHarita($salonId) : null;
        $q = $this->tahsilatlar($salonId, $f)->when($harita !== null, fn ($q) => $q->whereIn('id', array_keys($harita) ?: [0]));
        $toplam = (clone $q)->count();
        $satirlar = $q->orderBy('odeme_tarihi', 'desc')->orderBy('id', 'desc')->forPage($sayfa, self::SAYFA_BOYU)->get();

        $musteriler = $this->haritala('users', $satirlar->pluck('user_id'), ['id', 'name']);
        $personeller = $this->haritala('salon_personelleri', $satirlar->pluck('olusturan_id')->merge($satirlar->pluck('personel_id')), ['id', 'personel_adi']);
        $yontemler = $this->haritala('odeme_yontemleri', $satirlar->pluck('odeme_yontemi_id'), ['*']);
        $bankalar = $this->haritala('bankalar', $satirlar->pluck('banka_id'), ['*']);
        $urunSatislari = $this->haritala('urun_satislari', $satirlar->pluck('urun_satis_id'), ['*']);
        $ids = $satirlar->pluck('id')->all();
        $odemeler = [];
        foreach (KalemTuru::cases() as $tur) {
            $odemeler[$tur->value] = DB::table($tur->tahsilatTablosu())->whereIn('tahsilat_id', $ids)->orderBy('id')->get()->groupBy('tahsilat_id');
        }

        $data = $satirlar->map(function ($t) use ($harita, $musteriler, $personeller, $yontemler, $bankalar, $urunSatislari, $odemeler) {
            $satir = (array) $t;
            if ($harita !== null) {
                $satir['tutar'] = $harita[$t->id] ?? 0;
            }

            return array_merge($satir, [
                'musteri'          => self::satir($musteriler, $t->user_id ?? null),
                'odeme_yontemi'    => self::satir($yontemler, $t->odeme_yontemi_id ?? null),
                'banka'            => self::satir($bankalar, $t->banka_id ?? null),
                'olusturan'        => self::satir($personeller, $t->olusturan_id ?? null),
                'satici'           => self::satir($personeller, $t->personel_id ?? null),
                'hizmet_odemeleri' => ($odemeler['hizmet'][$t->id] ?? collect())->values(),
                'urun_odemeleri'   => ($odemeler['urun'][$t->id] ?? collect())->values(),
                'paket_odemeleri'  => ($odemeler['paket'][$t->id] ?? collect())->values(),
                'urun_satisi'      => self::satir($urunSatislari, $t->urun_satis_id ?? null),
            ]);
        })->all();

        return ['data' => $data] + ListeIstegi::meta($sayfa, self::SAYFA_BOYU, $toplam);
    }

    /** Harcayani olmayan masraf ("Kasa") listede cikmaz; tarih saatsiz karsilastirilir (eskiyle ayni). */
    public function masrafSayfasi(int $salonId, array $f, ?string $harcayan, int $sayfa): array
    {
        $q = DB::table('masraflar as m')
            ->join('salon_personelleri as h', 'h.id', '=', 'm.harcayan_id')
            ->where('m.salon_id', $salonId)
            ->when($f['tarih1'] !== null && $f['tarih2'] !== null, fn ($q) => $q->where('m.tarih', '>=', $f['tarih1'])->where('m.tarih', '<=', $f['tarih2']))
            ->when($f['odeme'], fn ($q) => $q->where('m.odeme_yontemi_id', $f['odeme']))
            ->where('h.personel_adi', 'like', '%'.$harcayan.'%');
        $toplam = (clone $q)->count();
        $satirlar = $q->orderBy('m.tarih', 'desc')->orderBy('m.id', 'desc')->forPage($sayfa, self::SAYFA_BOYU)->select('m.*', 'h.personel_adi as harcayan_adi')->get();

        $kategoriler = $this->haritala('masraf_kategorileri', $satirlar->pluck('masraf_kategori_id'), ['*']);
        $yontemler = $this->haritala('odeme_yontemleri', $satirlar->pluck('odeme_yontemi_id'), ['*']);

        $data = $satirlar->map(function ($m) use ($kategoriler, $yontemler) {
            $satir = (array) $m;
            unset($satir['harcayan_adi']);

            return array_merge($satir, [
                'harcayan'          => ['id' => $m->harcayan_id, 'personel_adi' => $m->harcayan_adi],
                'masraf_kategorisi' => self::satir($kategoriler, $m->masraf_kategori_id ?? null),
                'odeme_yontemi'     => self::satir($yontemler, $m->odeme_yontemi_id ?? null),
            ]);
        })->all();

        return ['data' => $data] + ListeIstegi::meta($sayfa, self::SAYFA_BOYU, $toplam);
    }

    public function gunSonu(int $salonId, string $t1, string $t2): array
    {
        $gizli = $this->tutar->faturaliMi($salonId);
        $harita = $gizli ? $this->faturaliHarita($salonId) : [];
        $tarih = fn (Builder $q, string $kolon) => $q->whereDate($kolon, '>=', $t1)->whereDate($kolon, '<=', $t2);

        $tahsilatlar = $tarih(DB::table('tahsilatlar as t'), 't.odeme_tarihi')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 't.olusturan_id')
            ->leftJoin('odeme_yontemleri as oy', 'oy.id', '=', 't.odeme_yontemi_id')
            ->where('t.salon_id', $salonId)
            ->when($gizli, fn ($q) => $q->whereIn('t.id', array_keys($harita) ?: [0]))
            ->orderBy('t.odeme_tarihi')
            ->get(['t.id', 't.user_id', 't.odeme_tarihi', 't.odeme_yontemi_id', 't.para_girisi', 't.tutar',
                'u.name as musteri', 'p.personel_adi as tahsil_eden', 'oy.odeme_yontemi as yontem']);

        $masraflar = $tarih(DB::table('masraflar as m'), 'm.tarih')
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 'm.harcayan_id')
            ->leftJoin('masraf_kategorileri as mk', 'mk.id', '=', 'm.masraf_kategori_id')
            ->leftJoin('odeme_yontemleri as oy', 'oy.id', '=', 'm.odeme_yontemi_id')
            ->where('m.salon_id', $salonId)
            ->orderBy('m.tarih')->orderBy('m.id')
            ->get(['m.tutar', 'm.odeme_yontemi_id', 'm.aciklama', 'm.harcayan_id', 'm.personel_gideri', 'm.personel_maas_odemesi_id',
                'mk.kategori', 'p.personel_adi as harcayan', 'oy.odeme_yontemi as yontem']);

        $kalem = fn (string $tablo, string $alias) => $tarih(DB::table($tablo.' as '.$alias)->join('tahsilatlar as t', 't.id', '=', $alias.'.tahsilat_id'), 't.odeme_tarihi')
            ->where('t.salon_id', $salonId);

        $personeller = $kalem('tahsilat_hizmetler', 'th')
            ->join('adisyon_hizmetler as ah', 'ah.id', '=', 'th.adisyon_hizmet_id')
            ->join('salon_personelleri as p', 'p.id', '=', 'ah.personel_id')
            ->whereNotNull('ah.personel_id')
            ->groupBy('p.id', 'p.personel_adi')->orderByDesc('ciro')->limit(15)
            ->get(['p.personel_adi', DB::raw('COUNT(DISTINCT th.id) as adet'), DB::raw('SUM(th.tutar) as ciro')]);

        $topHizmet = $kalem('tahsilat_hizmetler', 'th')
            ->join('adisyon_hizmetler as ah', 'ah.id', '=', 'th.adisyon_hizmet_id')
            ->join('hizmetler as h', 'h.id', '=', 'ah.hizmet_id')
            ->groupBy('h.id', 'h.hizmet_adi')->orderByDesc('ciro')->limit(5)
            ->get(['h.hizmet_adi', DB::raw('COUNT(DISTINCT th.id) as adet'), DB::raw('SUM(th.tutar) as ciro')]);

        $topUrun = $kalem('tahsilat_urunler', 'tu')
            ->join('adisyon_urunler as au', 'au.id', '=', 'tu.adisyon_urun_id')
            ->leftJoin('urunler as u', 'u.id', '=', 'au.urun_id')
            ->groupBy('u.id', 'u.urun_adi')->orderByDesc('ciro')->limit(5)
            ->get([DB::raw("COALESCE(u.urun_adi,'Silinmiş Ürün') as urun_adi"), DB::raw('COUNT(DISTINCT tu.id) as adet'), DB::raw('SUM(tu.tutar) as ciro')]);

        return ['durum' => 'ok', 'baslangic' => $t1, 'bitis' => $t2]
            + MobilKasaHesabi::gunSonu($tahsilatlar, $masraflar, $gizli, $harita)
            + ['personeller' => $personeller, 'top_hizmet' => $topHizmet, 'top_urun' => $topUrun];
    }

    /** Faturasiz gizlemeye uymaz, tarih saatsiz karsilastirilir (eski devredenAylar ile ayni). */
    public function aylik(int $salonId, int $yil): array
    {
        $aylar = [];
        foreach (KasaRaporServisi::AYLAR as $i => $ad) {
            [$bas, $bit] = MobilKasaHesabi::ayAraligi($yil, $i + 1);
            $tahsilat = DB::table('tahsilatlar')->where('salon_id', $salonId)->where('odeme_tarihi', '>=', $bas)->where('odeme_tarihi', '<=', $bit)->sum('tutar');
            $masraf = DB::table('masraflar')->where('salon_id', $salonId)->where('tarih', '>=', $bas)->where('tarih', '<=', $bit)->sum('tutar');
            $aylar[] = MobilKasaHesabi::aySatiri($i + 1, $ad, $yil, $tahsilat, $masraf);
        }

        return $aylar;
    }

    private function tahsilatlar(int $salonId, array $f): Builder
    {
        return DB::table('tahsilatlar')->where('salon_id', $salonId)
            ->when($f['tarih1'] !== null && $f['tarih2'] !== null, fn ($q) => $q->whereDate('odeme_tarihi', '>=', $f['tarih1'])->whereDate('odeme_tarihi', '<=', $f['tarih2']))
            ->when($f['odeme'], fn ($q) => $q->where('odeme_yontemi_id', $f['odeme']));
    }

    /** @return array<int,float> tahsilat_id => salonun faturali adisyon kalemlerine dusen kisim */
    private function faturaliHarita(int $salonId): array
    {
        $satirlar = [];
        foreach (KalemTuru::cases() as $tur) {
            foreach (DB::table($tur->tahsilatTablosu().' as x')
                ->join($tur->kalemTablosu().' as k', 'k.id', '=', 'x.'.$tur->tahsilatFk())
                ->join('adisyonlar as a', 'a.id', '=', 'k.adisyon_id')
                ->where('a.salon_id', $salonId)->where('a.fatura_kesildi', 1)
                ->get(['x.tahsilat_id', 'x.tutar']) as $s) {
                $satirlar[] = $s;
            }
        }

        return MobilKasaHesabi::faturaliKisimlar($satirlar);
    }

    private static function satir($harita, mixed $id): ?object
    {
        return $id ? ($harita[$id] ?? null) : null;
    }

    /** @return \Illuminate\Support\Collection id => satir (bos id'ler sorgulanmaz) */
    private function haritala(string $tablo, $idler, array $kolonlar)
    {
        $idler = collect($idler)->filter()->unique()->values();

        return $idler->isEmpty() ? collect() : DB::table($tablo)->whereIn('id', $idler)->get($kolonlar)->keyBy('id');
    }
}
