<?php

namespace App\Services\Seans;

use App\Domain\Seans\SeansKalemTuru;
use App\Exceptions\BulunamadiException;
use Illuminate\Support\Facades\DB;

/**
 * Seans / seansli satis kalemi → salon cozumu (kapsam denetimi bunun uzerine kurulur).
 * Salon HER ZAMAN kaydin adisyonundan gelir, istekten degil: baska salonun kaydi
 * kapsam disi kalir (eski web/v1 uclari salon kontrolu yapmiyordu).
 */
class SeansKapsam
{
    /**
     * Kalem satiri: id, adisyon_id, seans_sayisi, katalog_id (paket_id|hizmet_id), salon_id, user_id.
     *
     * @throws BulunamadiException
     */
    public function kalem(SeansKalemTuru $tur, int $id): object
    {
        $katalog = $tur === SeansKalemTuru::PAKET ? 'k.paket_id' : 'k.hizmet_id';
        $kalem = DB::table($tur->tablo().' as k')
            ->join('adisyonlar as a', 'a.id', '=', 'k.adisyon_id')
            ->where('k.id', $id)
            ->first(['k.id', 'k.adisyon_id', 'k.seans_sayisi', DB::raw($katalog.' as katalog_id'), 'a.salon_id', 'a.user_id']);
        if (! $kalem || ! $kalem->salon_id) {
            throw new BulunamadiException('Paket / hizmet satışı bulunamadı');
        }

        return $kalem;
    }

    /**
     * Seans + bagli oldugu kalem.
     *
     * @return array{0:object,1:SeansKalemTuru,2:object} [seans, tur, kalem]
     *
     * @throws BulunamadiException
     */
    public function seans(int $id): array
    {
        $seans = DB::table('adisyon_paket_seanslar')->where('id', $id)->first();
        $tur = $seans ? SeansKalemTuru::seanstan($seans) : null;
        if (! $tur) {
            throw new BulunamadiException('Seans bulunamadı');
        }

        return [$seans, $tur, $this->kalem($tur, $tur->seansKalemId($seans))];
    }

    /**
     * Kalemin seans tutulan hizmetleri: paket → paket_hizmetler, tek hizmet → kendisi.
     *
     * @return array<int,string> [hizmet_id => hizmet_adi]
     */
    public function hizmetler(SeansKalemTuru $tur, object $kalem): array
    {
        $id = (int) $kalem->katalog_id;
        $paketMi = $tur === SeansKalemTuru::PAKET;

        return $this->kalemHizmetleri($paketMi ? [$id] : [], $paketMi ? [] : [$id])[$tur->value][$id] ?? [];
    }

    /**
     * TOPLU hizmet cozumu (liste ekrani N+1 yapmasin).
     *
     * @param  int[]  $paketIdler  katalog paket id'leri
     * @param  int[]  $hizmetIdler katalog hizmet id'leri
     * @return array{paket: array<int, array<int,string>>, hizmet: array<int, array<int,string>>}
     */
    public function kalemHizmetleri(array $paketIdler, array $hizmetIdler): array
    {
        $sonuc = ['paket' => [], 'hizmet' => []];
        if ($paketIdler) {
            $satirlar = DB::table('paket_hizmetler as ph')->join('hizmetler as h', 'h.id', '=', 'ph.hizmet_id')
                ->whereIn('ph.paket_id', array_unique($paketIdler))
                ->orderBy('ph.id')
                ->get(['ph.paket_id', 'h.id', 'h.hizmet_adi']);
            foreach ($satirlar as $s) {
                $sonuc['paket'][(int) $s->paket_id][(int) $s->id] = (string) $s->hizmet_adi;
            }
        }
        if ($hizmetIdler) {
            foreach (DB::table('hizmetler')->whereIn('id', array_unique($hizmetIdler))->pluck('hizmet_adi', 'id') as $id => $ad) {
                $sonuc['hizmet'][(int) $id] = [(int) $id => (string) $ad];
            }
        }

        return $sonuc;
    }
}
