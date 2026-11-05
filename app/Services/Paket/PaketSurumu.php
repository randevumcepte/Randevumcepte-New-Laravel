<?php

namespace App\Services\Paket;

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

        return DB::transaction(function () use ($paketId): int {
            $paket = (array) DB::table('paketler')->where('id', $paketId)->first();
            unset($paket['id']);
            $paket['aktif'] = 0;
            $kopyaId = DB::table('paketler')->insertGetId($paket);

            $this->satirlariKopyala('paket_hizmetler', ['paket_id' => $paketId], ['paket_id' => $kopyaId]);
            if (Schema::hasTable('personel_prim_oranlari')) { // eski satislarin detayli primi degismesin
                $this->satirlariKopyala('personel_prim_oranlari', ['tur' => 'paket', 'kalem_id' => $paketId], ['kalem_id' => $kopyaId]);
            }

            // Toplu guncelleme: model olayi calismaz, satisin kendi sure/limit bilgisi korunur.
            DB::table('adisyon_paketler')->where('paket_id', $paketId)->update(['paket_id' => $kopyaId]);

            return $kopyaId;
        });
    }

    private function satirlariKopyala(string $tablo, array $kosul, array $degisen): void
    {
        foreach (DB::table($tablo)->where($kosul)->get() as $satir) {
            $yeni = (array) $satir;
            unset($yeni['id']);
            DB::table($tablo)->insert($degisen + $yeni);
        }
    }
}
