<?php

namespace App\Services\Prim;

use App\Domain\Rapor\MobilPrimHesap;
use App\Domain\Rapor\PrimOdemeKurali;
use App\Domain\Satis\KalemTuru;
use App\Models\PersonelPrimOrani;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personel prim okuma — eski ApiController personelPrimHesaplaAyYil, primHakedisTopluApi, primOdemeListesiApi karsiligi.
 * Faturasiz gizleme prim hesabina uygulanmaz (eski faturasiziDahilEt=true). Yetki ve maskeleme controller'dadir.
 */
class MobilPrimServisi
{
    private const PARCA = 1000;

    public function aylik(int $salonId, int $personelId, mixed $ay, mixed $yil, string $simdi): array
    {
        $d = MobilPrimHesap::aralik($ay, $yil, null, $simdi);
        $harita = PersonelPrimOrani::harita($salonId);
        $kartlar = [];
        foreach ($this->adisyonlar($salonId, $d['baslangic'], $d['bitis']) as $a) {
            if ($kapsam = MobilPrimHesap::personelKapsami($a, $personelId)) {
                $kartlar[] = MobilPrimHesap::adisyon($kapsam, $harita);
            }
        }

        return MobilPrimHesap::aylikCevap($kartlar, $d['ay'], $d['yil']);
    }

    /** @param  ?int  $yalniz  verilirse yalniz bu personelin satiri (kendi hak edisini goren personel) */
    public function hakedis(int $salonId, mixed $ay, mixed $yil, mixed $gun, string $simdi, ?int $yalniz = null): array
    {
        $d = MobilPrimHesap::aralik($ay, $yil, $gun, $simdi);
        $bas = substr($d['baslangic'], 0, 10);
        $bit = substr($d['bitis'], 0, 10);

        $gunluk = collect();
        if ($d['gun'] && Schema::hasColumn('personel_maas_odemeleri', 'prim_gun')) {
            $gunluk = DB::table('personel_maas_odemeleri')->where('salon_id', $salonId)->where('odeme_tipi', 'prim')
                ->whereDate('prim_gun', $d['gun'])->get()->groupBy('personel_id');
        }

        $personeller = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', true)
            ->where(fn ($q) => $q->where('arsivli', false)->orWhereNull('arsivli'))
            ->when($yalniz !== null, fn ($q) => $q->where('id', $yalniz))
            ->orderBy('takvim_sirasi')->orderBy('id')->get();

        $odemeler = DB::table('personel_maas_odemeleri')->where('salon_id', $salonId)->where('donem', $d['donem'])->get()->groupBy('personel_id');
        $hareketler = DB::table('personel_prim_hareketleri')->where('salon_id', $salonId)->whereBetween('tarih', [$bas, $bit])->get();
        $varOlan = $this->telafiKaynaklari($hareketler);
        $hareketler = $hareketler->groupBy('personel_id');
        $giderler = DB::table('masraflar')->where('salon_id', $salonId)->where('personel_gideri', 1)->whereNotNull('harcayan_id')
            ->whereBetween('tarih', [$bas.' 00:00:00', $bit.' 23:59:59'])->groupBy('harcayan_id')
            ->select('harcayan_id', DB::raw('SUM(tutar) as toplam'))->pluck('toplam', 'harcayan_id');

        $harita = PersonelPrimOrani::harita($salonId);
        $adisyonlar = $this->adisyonlar($salonId, $d['baslangic'], $d['bitis']);

        $satirlar = [];
        foreach ($personeller as $p) {
            $kartlar = [];
            foreach ($adisyonlar as $a) {
                if ($kapsam = MobilPrimHesap::personelKapsami($a, (int) $p->id)) {
                    $kartlar[] = MobilPrimHesap::adisyon($kapsam, $harita);
                }
            }
            $o = $odemeler->get($p->id, collect());
            $h = $hareketler->get($p->id, collect());
            $satirlar[] = MobilPrimHesap::hakedisSatiri((array) $p, MobilPrimHesap::toplamlar($kartlar), [
                'maas'    => (float) $o->where('odeme_tipi', 'maas')->sum('tutar'),
                'prim'    => (float) $o->where('odeme_tipi', 'prim')->sum('tutar'),
                'diger'   => (float) $o->where('odeme_tipi', 'diger')->sum('tutar'),
                'bonus'   => (float) $h->where('tip', 'bonus')->sum('tutar'),
                'kesinti' => (float) $h->where('tip', 'kesinti')->sum('tutar'),
                'telafi'  => (float) $h->where('tip', 'telafi_devir')->sum(fn ($x) => $this->telafiTutari($x, $varOlan)),
                'masraf'  => (float) ($giderler[$p->id] ?? 0),
            ], $d['gun'] ? (float) $gunluk->get($p->id, collect())->sum('tutar') : null);
        }

        return ['mod' => $d['gun'] ? 'gunluk' : 'aylik', 'gun' => $d['gun'], 'donem' => $d['donem'], 'personeller' => $satirlar];
    }

    public function odemeListesi(int $salonId, int $personelId, mixed $donemHam, string $buAy): array
    {
        [$donem, $t1, $t2] = PrimOdemeKurali::listeDonemi($donemHam, $buAy);

        $odemeler = DB::table('personel_maas_odemeleri')->where('salon_id', $salonId)->where('personel_id', $personelId)->where('donem', $donem)
            ->orderBy('odeme_tarihi', 'desc')->orderBy('id', 'desc')->get()
            ->map(fn ($o) => [
                'id'            => $o->id,
                'odeme_tarihi'  => $o->odeme_tarihi ? substr((string) $o->odeme_tarihi, 0, 10) : null,
                'tutar'         => (float) $o->tutar,
                'odeme_tipi'    => PrimOdemeKurali::odemeTipi($o->odeme_tipi),
                'odeme_yontemi' => $o->odeme_yontemi,
                'aciklama'      => $o->aciklama,
            ]);

        $hareketler = DB::table('personel_prim_hareketleri')->where('salon_id', $salonId)->where('personel_id', $personelId)
            ->whereBetween('tarih', [$t1, $t2])->orderBy('tarih', 'desc')->orderBy('id', 'desc')->get()
            ->map(fn ($h) => [
                'id'       => $h->id,
                'tarih'    => $h->tarih ? substr((string) $h->tarih, 0, 10) : null,
                'tip'      => $h->tip,
                'tutar'    => (float) $h->tutar,
                'aciklama' => $h->aciklama,
            ]);

        return [
            'odemeler'      => $odemeler->values(),
            'hareketler'    => $hareketler->values(),
            'odenen_toplam' => (float) $odemeler->sum('tutar'),
            'odeme_sayisi'  => $odemeler->count(),
            'toplamlar'     => [
                'maas'    => (float) $odemeler->where('odeme_tipi', 'maas')->sum('tutar'),
                'prim'    => (float) $odemeler->where('odeme_tipi', 'prim')->sum('tutar'),
                'diger'   => (float) $odemeler->where('odeme_tipi', 'diger')->sum('tutar'),
                'bonus'   => (float) $hareketler->where('tip', 'bonus')->sum('tutar'),
                'kesinti' => (float) $hareketler->where('tip', 'kesinti')->sum('tutar'),
            ],
        ];
    }

    /** Donem adisyonlari, kalemleri ve kalem basina tahsilat toplami; tarih azalan (eski sorgu sirasi). */
    private function adisyonlar(int $salonId, string $bas, string $bit): array
    {
        $adisyonlar = DB::table('adisyonlar as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.salon_id', $salonId)->whereBetween('a.tarih', [$bas, $bit])->orderBy('a.tarih', 'desc')
            ->get(['a.id', 'a.tarih', 'a.created_at', 'u.name as musteri'])
            ->map(fn ($a) => (array) $a + ['hizmetler' => [], 'urunler' => [], 'paketler' => []])
            ->keyBy('id')->all();
        if (! $adisyonlar) {
            return [];
        }

        $kalemler = [];
        foreach (KalemTuru::cases() as $tur) {
            $kalemler[$tur->value] = $this->kalemler($tur, array_keys($adisyonlar));
        }
        $personeller = $this->personeller(array_merge(...array_values(array_map(fn ($l) => array_column($l, 'personel_id'), $kalemler))));

        foreach (KalemTuru::cases() as $tur) {
            foreach ($kalemler[$tur->value] as $k) {
                $k['personel'] = $personeller[$k['personel_id']] ?? null;
                $adisyonlar[$k['adisyon_id']][$tur->value.'ler'][] = $k;
            }
        }

        return array_values($adisyonlar);
    }

    /** @return array<int,array> */
    private function kalemler(KalemTuru $tur, array $adisyonIdleri): array
    {
        $kolonlar = ['k.id', 'k.adisyon_id', 'k.fiyat', 'k.indirim_tutari', 'k.personel_id', 'k.'.$tur->katalogFk().' as katalog_id', 'c.'.$tur->adKolonu().' as ad'];
        if ($tur === KalemTuru::Hizmet) {
            array_push($kolonlar, 'k.randevu_id', 'k.seans_sayisi');
        }

        $satirlar = [];
        foreach (array_chunk($adisyonIdleri, self::PARCA) as $parca) {
            foreach (DB::table($tur->kalemTablosu().' as k')->leftJoin($tur->katalogTablosu().' as c', 'c.id', '=', 'k.'.$tur->katalogFk())
                ->whereIn('k.adisyon_id', $parca)->orderBy('k.id')->get($kolonlar) as $k) {
                $satirlar[$k->id] = (array) $k + ['tahsilat' => 0];
            }
        }
        foreach (array_chunk(array_keys($satirlar), self::PARCA) as $parca) {
            foreach (DB::table($tur->tahsilatTablosu())->whereIn($tur->tahsilatFk(), $parca)->orderBy('id')->get([$tur->tahsilatFk().' as kalem', 'tutar']) as $x) {
                $satirlar[$x->kalem]['tahsilat'] += $x->tutar;
            }
        }

        return array_values($satirlar);
    }

    /** @return array<int,array> id => personel */
    private function personeller(array $idler): array
    {
        $idler = array_values(array_unique(array_filter($idler)));
        if (! $idler) {
            return [];
        }

        return DB::table('salon_personelleri')->whereIn('id', $idler)->get()->map(fn ($p) => (array) $p)->keyBy('id')->all();
    }

    /** Telafi devrinin kaynak satisi silinmisse hareket yok sayilir (yetim koruma). */
    private function telafiTutari(object $h, array $varOlan): float
    {
        $ah = $h->kaynak_adisyon_hizmet_id ?? null;
        $ap = $h->kaynak_adisyon_paket_id ?? null;
        if (($ah && ! isset($varOlan['hizmet'][$ah])) || ($ap && ! isset($varOlan['paket'][$ap]))) {
            return 0.0;
        }

        return (float) $h->tutar;
    }

    /** @return array{hizmet:array<int,bool>, paket:array<int,bool>} */
    private function telafiKaynaklari(Collection $hareketler): array
    {
        $bul = function (string $tablo, string $alan) use ($hareketler) {
            $idler = $hareketler->where('tip', 'telafi_devir')->pluck($alan)->filter()->unique()->values();

            return $idler->isEmpty() ? [] : array_fill_keys(DB::table($tablo)->whereIn('id', $idler)->pluck('id')->all(), true);
        };

        return ['hizmet' => $bul('adisyon_hizmetler', 'kaynak_adisyon_hizmet_id'), 'paket' => $bul('adisyon_paketler', 'kaynak_adisyon_paket_id')];
    }
}
