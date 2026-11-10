<?php

namespace App\Services\Kasa;

use App\Domain\Rapor\OdemeKovasi;
use App\Domain\Rapor\RaporDonemi;
use App\Domain\Satis\KalemTuru;
use Illuminate\Support\Facades\DB;

/**
 * Gun Sonu Raporu — eski gun_sonu_raporu (web) + gunSonuRaporu (mobil) TEK karsiligi.
 *
 * Ozet: odeme yontemi kovalarinda gelir / masraf / net, islem + musteri sayisi, ortalama
 * sepet, personel bazli ciro, en cok satan hizmet / urun. Tam detay: satislar, isletme
 * masraflari, personel giderleri / odemeleri (saglama: satis − masraf − personel gideri = net).
 * Duzeltilenler: faturasiz gizlemede personel cirosu ve en cok satanlar da faturali kisma
 * gore (eskiden toplamla tutmuyordu); personel cirosu yalniz hizmet degil tum kalemler.
 */
class GunSonuServisi
{
    public function __construct(private KasaRaporServisi $kasa, private KasaTutar $tutar) {}

    public function rapor(int $salonId, RaporDonemi $d): array
    {
        $faturali = $this->tutar->faturaliMi($salonId);

        $tahsilatlar = $this->kasa->gelirSorgusu($salonId, $faturali, $d)
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 't.olusturan_id')
            ->leftJoin('odeme_yontemleri as oy', 'oy.id', '=', 't.odeme_yontemi_id')
            ->orderBy('t.odeme_tarihi')->orderBy('t.id')
            ->get(['t.id', 't.user_id', 't.odeme_tarihi', 't.odeme_yontemi_id', 't.para_girisi',
                'u.name as musteri', 'p.personel_adi as tahsil_eden', 'oy.odeme_yontemi as yontem',
                DB::raw(KasaTutar::tutarSql($faturali).' as tutar')]);

        $masraflar = $this->kasa->giderSorgusu($salonId, $d)
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 'm.harcayan_id')
            ->leftJoin('masraf_kategorileri as mk', 'mk.id', '=', 'm.masraf_kategori_id')
            ->leftJoin('odeme_yontemleri as oy', 'oy.id', '=', 'm.odeme_yontemi_id')
            ->orderBy('m.tarih')->orderBy('m.id')
            ->get(['m.id', 'm.tarih', 'm.tutar', 'm.odeme_yontemi_id', 'm.aciklama', 'm.notlar', 'm.harcayan_id',
                'm.personel_gideri', 'm.personel_maas_odemesi_id', 'p.personel_adi as harcayan', 'mk.kategori', 'oy.odeme_yontemi as yontem']);

        $gelir = OdemeKovasi::topla($tahsilatlar->map(fn ($t) => ['odeme_yontemi_id' => $t->odeme_yontemi_id, 'toplam' => $t->tutar]));
        $masraf = OdemeKovasi::topla($masraflar->map(fn ($m) => ['odeme_yontemi_id' => $m->odeme_yontemi_id, 'toplam' => $m->tutar]));
        $net = [];
        foreach ($gelir as $k => $v) {
            $net[$k] = round($v - $masraf[$k], 2);
        }

        $satis = $tahsilatlar->where('para_girisi', '!=', 1);
        $islemSay = $satis->count();
        $gercekGelir = (float) $satis->sum('tutar');

        $satislar = $tahsilatlar->map(fn ($t) => [
            'saat'        => substr((string) $t->odeme_tarihi, 11, 5),
            'tarih'       => substr((string) $t->odeme_tarihi, 0, 10),
            'musteri'     => $t->para_girisi ? 'Kasa Girişi' : ($t->musteri ?: '-'),
            'tahsil_eden' => $t->tahsil_eden,
            'yontem'      => $t->yontem,
            'para_girisi' => (bool) $t->para_girisi,
            'tutar'       => round((float) $t->tutar, 2),
        ])->values()->all();

        $isletmeMasraflari = [];
        $personelGiderleri = [];
        foreach ($masraflar as $m) {
            $satir = KasaRaporServisi::masrafMetni($m) + ['yontem' => $m->yontem, 'tutar' => round((float) $m->tutar, 2)];
            if ($m->personel_gideri || $m->personel_maas_odemesi_id) {
                $personelGiderleri[] = $satir + ['tip' => $m->personel_gideri ? 'Personel Gideri' : 'Personel Ödemesi'];
            } else {
                $isletmeMasraflari[] = $satir;
            }
        }

        $gelirToplam = round(array_sum($gelir), 2);
        $masrafToplam = round(array_sum($masraf), 2);

        return [
            'donem'                 => $d->dizi(),
            'gelir'                 => $gelir,
            'gelir_toplam'          => $gelirToplam,
            'masraf'                => $masraf,
            'masraf_toplam'         => $masrafToplam,
            'net'                   => $net,
            'net_toplam'            => round($gelirToplam - $masrafToplam, 2),
            // Kullanilmayan yontem (gelirde de giderde de 0) satiri cikmaz.
            'odeme_etiketleri'      => OdemeKovasi::liste(array_map(fn ($k) => abs($gelir[$k] ?? 0) + abs($masraf[$k] ?? 0), array_combine(array_keys($gelir), array_keys($gelir)))),
            'islem_say'             => $islemSay,
            'musteri_say'           => $satis->pluck('user_id')->filter()->unique()->count(),
            'ort_sepet'             => $islemSay > 0 ? round($gercekGelir / $islemSay, 2) : 0.0,
            'personeller'           => $this->personelCirosu($salonId, $d, $faturali),
            'top_hizmet'            => $this->enCokSatan($salonId, $d, $faturali, KalemTuru::Hizmet),
            'top_urun'              => $this->enCokSatan($salonId, $d, $faturali, KalemTuru::Urun),
            'satislar'              => $satislar,
            'isletme_masraflari'    => $isletmeMasraflari,
            'personel_giderleri'    => $personelGiderleri,
            'isletme_masraf_toplam' => round(array_sum(array_column($isletmeMasraflari, 'tutar')), 2),
            'personel_gider_toplam' => round(array_sum(array_column($personelGiderleri, 'tutar')), 2),
        ];
    }

    /** Donemde kalem tahsilatlari (tur bazli) — personel cirosu ve en cok satanlarin ortak kaynagi. */
    private function kalemTahsilatlari(int $salonId, RaporDonemi $d, bool $faturali, KalemTuru $tur)
    {
        return DB::table($tur->tahsilatTablosu().' as x')
            ->join('tahsilatlar as t', 't.id', '=', 'x.tahsilat_id')
            ->join($tur->kalemTablosu().' as k', 'k.id', '=', 'x.'.$tur->tahsilatFk())
            ->when($faturali, fn ($q) => $q->join('adisyonlar as fa', 'fa.id', '=', 'k.adisyon_id')->where('fa.fatura_kesildi', 1))
            ->where('t.salon_id', $salonId)
            ->whereBetween('t.odeme_tarihi', [$d->basSaat(), $d->bitSaat()]);
    }

    private function personelCirosu(int $salonId, RaporDonemi $d, bool $faturali): array
    {
        $birlesik = null;
        foreach (KalemTuru::cases() as $tur) {
            $q = $this->kalemTahsilatlari($salonId, $d, $faturali, $tur)->whereNotNull('k.personel_id')
                ->select(['k.personel_id', 'x.id as kalem_tahsilat_id', 'x.tutar']);
            $birlesik = $birlesik ? $birlesik->unionAll($q) : $q;
        }

        return DB::query()->fromSub($birlesik, 's')
            ->join('salon_personelleri as p', 'p.id', '=', 's.personel_id')
            ->groupBy('p.id', 'p.personel_adi')
            ->orderByDesc('ciro')->limit(15)
            ->get(['p.personel_adi', DB::raw('COUNT(*) as adet'), DB::raw('SUM(s.tutar) as ciro')])
            ->map(fn ($r) => ['personel_adi' => $r->personel_adi, 'adet' => (int) $r->adet, 'ciro' => round((float) $r->ciro, 2)])
            ->all();
    }

    private function enCokSatan(int $salonId, RaporDonemi $d, bool $faturali, KalemTuru $tur): array
    {
        $ad = $tur->adKolonu();

        return $this->kalemTahsilatlari($salonId, $d, $faturali, $tur)
            ->leftJoin($tur->katalogTablosu().' as c', 'c.id', '=', 'k.'.$tur->katalogFk())
            ->groupBy('c.id', 'c.'.$ad)
            ->orderByDesc('ciro')->limit(5)
            ->get([DB::raw("COALESCE(c.{$ad}, 'Silinmiş ".$tur->etiket()."') as ad"), DB::raw('COUNT(DISTINCT x.id) as adet'), DB::raw('SUM(x.tutar) as ciro')])
            ->map(fn ($r) => ['ad' => $r->ad, 'adet' => (int) $r->adet, 'ciro' => round((float) $r->ciro, 2)])
            ->all();
    }
}
