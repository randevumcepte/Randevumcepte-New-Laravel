<?php

namespace App\Services\Tahsilat;

use App\Domain\Tahsilat\AdisyonHesap;
use App\Models\Adisyonlar;
use Illuminate\Support\Facades\DB;

/**
 * Satis (adisyon) LISTE servisi — Faz 1 salt okuma.
 *
 * Eski v1 `satislar`/`adisyon_yukle` ile PARITE:
 *   - NET    = Σ(kalem.fiyat − indirim_tutari)         [korelasyonlu alt-sorgu]
 *   - ODENEN = Σ kalem tahsilat (tahsilat_*.tutar)       [kalem-split, mobil parite]
 *   - durum  = (net − odenen) > 0 ? acik : kapali
 * Sayimlar (acik/kapali) tek fromSub aggregate ile; sayfa satirlari kurus-kesin.
 * Hicbir sey YAZMAZ.
 */
class SatisListeServisi
{
    /** NET (per adisyonlar.id) — korelasyonlu alt-sorgu ifadesi. */
    private const NET =
        '((SELECT COALESCE(SUM(COALESCE(h.fiyat,0)-COALESCE(h.indirim_tutari,0)),0) FROM adisyon_hizmetler h WHERE h.adisyon_id=adisyonlar.id)'
        .'+(SELECT COALESCE(SUM(COALESCE(u.fiyat,0)-COALESCE(u.indirim_tutari,0)),0) FROM adisyon_urunler u WHERE u.adisyon_id=adisyonlar.id)'
        .'+(SELECT COALESCE(SUM(COALESCE(p.fiyat,0)-COALESCE(p.indirim_tutari,0)),0) FROM adisyon_paketler p WHERE p.adisyon_id=adisyonlar.id))';

    /** ODENEN (per adisyonlar.id) — kalem tahsilat tablolari (mobil parite). */
    private const ODENEN =
        '((SELECT COALESCE(SUM(th.tutar),0) FROM tahsilat_hizmetler th JOIN adisyon_hizmetler ah ON th.adisyon_hizmet_id=ah.id WHERE ah.adisyon_id=adisyonlar.id)'
        .'+(SELECT COALESCE(SUM(tu.tutar),0) FROM tahsilat_urunler tu JOIN adisyon_urunler au ON tu.adisyon_urun_id=au.id WHERE au.adisyon_id=adisyonlar.id)'
        .'+(SELECT COALESCE(SUM(tp.tutar),0) FROM tahsilat_paketler tp JOIN adisyon_paketler ap ON tp.adisyon_paket_id=ap.id WHERE ap.adisyon_id=adisyonlar.id))';

    public function liste(int $salonId, array $o): array
    {
        $tarih1 = $o['tarih1'] ?? null;
        $tarih2 = $o['tarih2'] ?? null;
        $acikKapali = $o['acik_kapali'] ?? null;          // '1'=acik, '0'=kapali, null=hepsi
        $kisitPersonelId = $o['kisit_personel_id'] ?? null; // null=tum satislar
        $page = max(1, (int) ($o['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($o['per_page'] ?? 20)));

        // Kapsam zaten controller'da (salon_id). Burada filtre + personel kapsami.
        $base = Adisyonlar::query()->where('salon_id', $salonId);
        if ($tarih1) {
            $base->whereDate('tarih', '>=', $tarih1);
        }
        if ($tarih2) {
            $base->whereDate('tarih', '<=', $tarih2);
        }
        if ($kisitPersonelId) {
            // Sadece kullanicinin KENDI kalemlerinin gectigi adisyonlar.
            $base->where(function ($q) use ($kisitPersonelId) {
                $q->whereExists(fn ($s) => $s->from('adisyon_hizmetler')->whereColumn('adisyon_id', 'adisyonlar.id')->where('personel_id', $kisitPersonelId))
                    ->orWhereExists(fn ($s) => $s->from('adisyon_urunler')->whereColumn('adisyon_id', 'adisyonlar.id')->where('personel_id', $kisitPersonelId))
                    ->orWhereExists(fn ($s) => $s->from('adisyon_paketler')->whereColumn('adisyon_id', 'adisyonlar.id')->where('personel_id', $kisitPersonelId));
            });
        }

        // Sayimlar (acik/kapali) — scope uzerinde tek aggregate.
        $sayac = DB::query()
            ->fromSub((clone $base)->selectRaw('('.self::NET.' - '.self::ODENEN.') as bakiye'), 's')
            ->selectRaw('SUM(CASE WHEN s.bakiye > 0 THEN 1 ELSE 0 END) acik, SUM(CASE WHEN s.bakiye <= 0 THEN 1 ELSE 0 END) kapali')
            ->first();
        $acikSayisi = (int) ($sayac->acik ?? 0);
        $kapaliSayisi = (int) ($sayac->kapali ?? 0);

        // Sayfa sorgusu + acik_kapali filtre (HAVING, alias uzerinde).
        $liste = (clone $base)->selectRaw(
            'adisyonlar.id, adisyonlar.tarih, adisyonlar.user_id, '
            .self::NET.' as net_tl, '.self::ODENEN.' as odenen_tl'
        );
        if ($acikKapali === '1') {
            $liste->havingRaw('(net_tl - odenen_tl) > 0');
        } elseif ($acikKapali === '0') {
            $liste->havingRaw('(net_tl - odenen_tl) <= 0');
        }

        $toplam = $acikKapali === '1' ? $acikSayisi
            : ($acikKapali === '0' ? $kapaliSayisi : $acikSayisi + $kapaliSayisi);
        $lastPage = (int) max(1, ceil(($toplam ?: 1) / $perPage));

        $rows = $liste->orderByDesc('adisyonlar.id')->forPage($page, $perPage)->get();

        $data = $rows->map(function ($r) {
            $net = AdisyonHesap::tlToKurus($r->net_tl);
            $od = AdisyonHesap::tlToKurus($r->odenen_tl);
            $kalan = $net - $od;

            return [
                'id'           => (int) $r->id,
                'tarih'        => $r->tarih,
                'user_id'      => $r->user_id !== null ? (int) $r->user_id : null,
                'net'          => AdisyonHesap::kurusToTl($net),
                'odenen'       => AdisyonHesap::kurusToTl($od),
                'kalan'        => AdisyonHesap::kurusToTl($kalan),
                'net_kurus'    => $net,
                'odenen_kurus' => $od,
                'kalan_kurus'  => $kalan,
                'durum'        => $kalan > 0 ? 'acik' : 'kapali',
            ];
        })->values();

        return [
            'data'          => $data,
            'acik_sayisi'   => $acikSayisi,
            'kapali_sayisi' => $kapaliSayisi,
            'current_page'  => $page,
            'last_page'     => $lastPage,
            'per_page'      => $perPage,
        ];
    }
}
