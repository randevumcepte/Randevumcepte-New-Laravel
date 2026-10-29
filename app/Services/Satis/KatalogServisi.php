<?php

namespace App\Services\Satis;

use Illuminate\Support\Facades\DB;

/**
 * Katalog (salon'a özel hizmet/ürün/paket) listesi — satış ekranı ekle-dropdownları.
 * Eski sistemle BİREBİR kaynaklar:
 *   - Hizmet: salon_sunulan_hizmetler (salon fiyatı baslangic_fiyat, sure_dk) + hizmetler.hizmet_adi
 *   - Ürün  : urunler (birim fiyat, stok_adedi)
 *   - Paket : paketler + SUM(paket_hizmetler.fiyat/seans) (yoksa paketler.fiyat/miktar)
 * Salt okuma; hiçbir şey yazmaz.
 */
class KatalogServisi
{
    public function listele(int $salonId): array
    {
        $hizmetler = DB::table('salon_sunulan_hizmetler as sh')
            ->join('hizmetler as h', 'h.id', '=', 'sh.hizmet_id')
            ->where('sh.salon_id', $salonId)
            ->where('sh.aktif', 1)
            ->orderBy('h.hizmet_adi')
            ->get(['sh.hizmet_id', 'h.hizmet_adi as ad', 'sh.baslangic_fiyat as fiyat', 'sh.sure_dk as sure'])
            ->map(fn ($r) => [
                'hizmet_id' => (int) $r->hizmet_id,
                'ad'        => $r->ad,
                'fiyat'     => $r->fiyat,
                'sure'      => $r->sure,
            ])->all();

        $urunler = DB::table('urunler')
            ->where('salon_id', $salonId)
            ->where('aktif', 1)
            ->orderBy('urun_adi')
            ->get(['id', 'urun_adi as ad', 'fiyat', 'stok_adedi as stok'])
            ->map(fn ($r) => [
                'urun_id' => (int) $r->id,
                'ad'      => $r->ad,
                'fiyat'   => $r->fiyat,   // BİRİM fiyat
                'stok'    => $r->stok,
            ])->all();

        $paketler = DB::table('paketler as p')
            ->leftJoin('paket_hizmetler as ph', 'ph.paket_id', '=', 'p.id')
            ->where('p.salon_id', $salonId)
            ->where('p.aktif', 1)
            ->groupBy('p.id', 'p.paket_adi', 'p.fiyat', 'p.miktar')
            ->orderBy('p.paket_adi')
            ->get([
                'p.id',
                'p.paket_adi as ad',
                DB::raw('COALESCE(NULLIF(SUM(ph.fiyat),0), p.fiyat) as fiyat'),
                DB::raw('COALESCE(NULLIF(SUM(ph.seans),0), p.miktar) as seans'),
            ])
            ->map(fn ($r) => [
                'paket_id' => (int) $r->id,
                'ad'       => $r->ad,
                'fiyat'    => $r->fiyat,
                'seans'    => $r->seans,
            ])->all();

        return [
            'hizmetler' => $hizmetler,
            'urunler'   => $urunler,
            'paketler'  => $paketler,
        ];
    }
}
