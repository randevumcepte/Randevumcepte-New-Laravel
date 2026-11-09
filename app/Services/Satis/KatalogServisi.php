<?php

namespace App\Services\Satis;

use App\Models\Salonlar;
use App\Services\Paket\PaketSorgusu;
use Illuminate\Support\Facades\DB;

/**
 * Katalog (salon'a özel hizmet/ürün/paket) listesi — satış ekranı ekle-dropdownları.
 * Eski sistemle BİREBİR kaynaklar:
 *   - Hizmet: salon_sunulan_hizmetler (salon fiyatı baslangic_fiyat, sure_dk) + hizmetler.hizmet_adi
 *   - Ürün  : urunler (birim fiyat, stok_adedi)
 *   - Paket : PaketSorgusu (etkin fiyat/seans = Domain\Paket\PaketTutari)
 * Salt okuma; hiçbir şey yazmaz.
 */
class KatalogServisi
{
    public function __construct(private PaketSorgusu $paketler) {}

    public function listele(int $salonId): array
    {
        $hizmetler = $this->hizmetler($salonId);

        $urunler = DB::table('urunler')
            ->where('salon_id', $salonId)
            ->where('aktif', 1)
            ->orderBy('urun_adi')
            ->get(['id', 'urun_adi as ad', 'fiyat', 'stok_adedi as stok', 'tip'])
            ->map(fn ($r) => [
                'urun_id' => (int) $r->id,
                'ad'      => trim((string) $r->ad),
                'fiyat'   => $r->fiyat,   // BİRİM fiyat
                'stok'    => $r->stok,
                'tip'     => $r->tip ?: 'satis', // Domain\Stok\UrunTipi (sarf = satilmaz)
            ])->all();

        // Etkin fiyat ve bolge basina satis seansi tek kuraldan (PaketTutari) — SQL'de tekrar yazilmaz.
        // Paket satis bileseni (v2-paket-satis.js) icin sinirsiz/gecerlilik, sure (dk) ve hizmetler de gider.
        $sureler = array_column($hizmetler, 'sure', 'hizmet_id');
        $paketler = array_map(fn (array $p) => [
            'paket_id'         => $p['id'],
            'ad'               => trim($p['paket_adi']),
            'fiyat'            => $p['fiyat'],
            'seans'            => $p['satis_seans'],
            'satis_seans'      => $p['satis_seans'],
            'sinirsiz'         => $p['sinirsiz'],
            'gecerlilik_metni' => $p['gecerlilik_metni'],
            'gunluk_limit'     => $p['gunluk_limit'],
            'sure'             => $p['sure'] ?? (array_sum(array_map(fn ($h) => (int) ($sureler[$h['hizmet_id']] ?? 0), $p['hizmetler'])) ?: null), // paket suresi, yoksa hizmet sureleri
            'hizmetler'        => array_map(fn ($h) => ['hizmet_id' => $h['hizmet_id'], 'hizmet_adi' => $h['hizmet_adi'], 'seans' => $h['seans'], 'fiyat' => $h['fiyat']], $p['hizmetler']),
        ], $this->paketler->listele($salonId, ['sirala' => 'paket_adi', 'yon' => 'asc'], true)['data']);

        return [
            'hizmetler' => $hizmetler,
            'urunler'   => $urunler,
            'paketler'  => $paketler,
            // Kalem personeli (hizmet veren / satıcı) seçimi: aktif personel, ada göre (eski personel_listesi_getir).
            'personeller' => DB::table('salon_personelleri')
                ->where('salon_id', $salonId)->where('aktif', 1)->orderBy('personel_adi')
                ->get(['id', 'personel_adi'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'ad' => trim((string) $p->personel_adi)])->all(),
            'hizmet_personel_zorunlu' => Salonlar::hizmetPersonelZorunlu($salonId),
        ];
    }

    /** Salonun aktif sundugu hizmetler (satis dropdown'u, paket tanimi hizmet secimi). */
    public function hizmetler(int $salonId): array
    {
        // Hizmet başına TEK kayıt (aynı hizmet_id için birden fazla salon offering
        // olabilir → dropdown'da tekrar oluyordu). Temsilci: en yüksek fiyat/süre.
        return DB::table('salon_sunulan_hizmetler as sh')
            ->join('hizmetler as h', 'h.id', '=', 'sh.hizmet_id')
            ->where('sh.salon_id', $salonId)
            ->where('sh.aktif', 1)
            ->groupBy('sh.hizmet_id', 'h.hizmet_adi')
            ->orderBy('h.hizmet_adi')
            ->get(['sh.hizmet_id', 'h.hizmet_adi as ad', DB::raw('MAX(sh.baslangic_fiyat) as fiyat'), DB::raw('MAX(sh.sure_dk) as sure')])
            ->map(fn ($r) => [
                'hizmet_id' => (int) $r->hizmet_id,
                'ad'        => trim((string) $r->ad),
                'fiyat'     => $r->fiyat,
                'sure'      => $r->sure,
            ])->all();
    }
}
