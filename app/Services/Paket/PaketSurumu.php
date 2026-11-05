<?php

namespace App\Services\Paket;

use App\Models\PaketHizmetler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satilmis paketin hizmetleri, katalog duzenlenince DEGISMEMELI.
 * Eski monolit karsiligi: App\Services\PaketSurumu (iki repoda ayni kural).
 *
 * Satilan paket yalniz paket_id tutar, hizmetleri paket_hizmetler'den okunur. Hizmet listesi
 * degisince eski satislar pasif (aktif=0) bir kopyaya tasinir. Katalog paketinin id'si AYNI kalir:
 * kampanya, on gorusme ve acik ekranlar etkilenmez, satislarin okuma kodu degismez.
 */
class PaketSurumu
{
    /** @param array<int,int|string> $a @param array<int,int|string> $b */
    public static function ayniHizmetler(array $a, array $b): bool
    {
        $duzenle = function (array $x): array {
            $x = array_values(array_unique(array_map('intval', $x)));
            sort($x);

            return $x;
        };

        return $duzenle($a) === $duzenle($b);
    }

    /**
     * Katalog paketi guncellenmeden ONCE cagrilir (eski fiyat/seans/hizmetler kopyalansin).
     * Hizmet listesi ayniysa ya da paket hic satilmadiysa hicbir sey yapmaz.
     *
     * @return int|null olusan pasif kopyanin id'si
     */
    public function satilmislariDondur(int $paketId, array $yeniHizmetIdler): ?int
    {
        $eski = DB::table('paket_hizmetler')->where('paket_id', $paketId)->pluck('hizmet_id')->all();
        if (self::ayniHizmetler($eski, $yeniHizmetIdler) || ! DB::table('adisyon_paketler')->where('paket_id', $paketId)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($paketId, $eski): int {
            $kopyaId = $this->kopyaAc($paketId, $eski);

            // Toplu guncelleme: model olayi calismaz, satisin kendi sure/limit bilgisi korunur.
            DB::table('adisyon_paketler')->where('paket_id', $paketId)->update(['paket_id' => $kopyaId]);

            return $kopyaId;
        });
    }

    /**
     * Satista secilen hizmetlere gore satilacak paket: liste katalogla ayniysa katalog paketi,
     * farkliysa o satisa ozel pasif kopya. Katalog paketi degismez. Hizmet sahipligi cagirana ait.
     *
     * @param  array<int,int|string>|null  $secilenHizmetler  bos/null ise katalog listesi
     */
    public function satisPaketi(int $paketId, ?array $secilenHizmetler): int
    {
        $secilen = array_values(array_unique(array_filter(array_map('intval', $secilenHizmetler ?? []))));
        if (! $secilen || self::ayniHizmetler(DB::table('paket_hizmetler')->where('paket_id', $paketId)->pluck('hizmet_id')->all(), $secilen)) {
            return $paketId;
        }

        return DB::transaction(fn (): int => $this->kopyaAc($paketId, $secilen));
    }

    /**
     * Secilen hizmetlere gore beklenen fiyat. Pakette hizmet bazli fiyat yoksa null (paket fiyati gecerli).
     * Paketten gelen hizmet kendi fiyatiyla, yeni eklenen salon fiyatiyla toplanir.
     */
    public function secimFiyati(int $paketId, int $salonId, array $secilenHizmetler): ?float
    {
        $katalog = DB::table('paket_hizmetler')->where('paket_id', $paketId)->pluck('fiyat', 'hizmet_id');
        if ($katalog->max() <= 0) {
            return null;
        }
        $toplam = 0.0;
        foreach (array_unique(array_map('intval', $secilenHizmetler)) as $hizmetId) {
            $toplam += (float) ($katalog[$hizmetId] ?? DB::table('salon_sunulan_hizmetler')
                ->where('salon_id', $salonId)->where('hizmet_id', $hizmetId)->max('baslangic_fiyat'));
        }

        return round($toplam, 2);
    }

    /** Pasif paket kopyasi: katalogdaki hizmetin seans/fiyati korunur, yeni hizmet bos eklenir. Prim oranlari da kopyalanir. */
    private function kopyaAc(int $paketId, array $hizmetIdler): int
    {
        $paket = (array) DB::table('paketler')->where('id', $paketId)->first();
        unset($paket['id']);
        $paket['aktif'] = 0;
        $kopyaId = DB::table('paketler')->insertGetId($paket);

        $mevcut = DB::table('paket_hizmetler')->where('paket_id', $paketId)->get()->keyBy('hizmet_id');
        foreach (array_unique(array_map('intval', $hizmetIdler)) as $hizmetId) {
            $eski = $mevcut->get($hizmetId);
            // Yeni eklenen hizmette seans/fiyat gonderilmez: kolonlar NULL kabul etmiyor, varsayilan gecerli.
            PaketHizmetler::create(['paket_id' => $kopyaId, 'hizmet_id' => $hizmetId] + ($eski ? ['seans' => $eski->seans, 'fiyat' => $eski->fiyat] : []));
        }

        if (Schema::hasTable('personel_prim_oranlari')) {
            foreach (DB::table('personel_prim_oranlari')->where('tur', 'paket')->where('kalem_id', $paketId)->get() as $o) {
                $satir = (array) $o;
                unset($satir['id']);
                DB::table('personel_prim_oranlari')->insert(['kalem_id' => $kopyaId] + $satir);
            }
        }

        return $kopyaId;
    }
}
