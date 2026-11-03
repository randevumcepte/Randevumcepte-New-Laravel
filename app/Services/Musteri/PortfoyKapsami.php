<?php

namespace App\Services\Musteri;

use App\Services\Yetki\YetkiServisi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Musteri PORTFOY kapsami — 'musteri.tum_portfoy_gor' kapali personel yalnizca KENDI
 * portfoyunu gorur (liste, sayaclar, detay ve alt kaynaklar AYNI kurali kullanir).
 *
 * Kendi portfoyu (eski PersonelYetkiServisi::kendiPortfoyUserIds + docblock niyeti):
 *   a) bu salondaki adisyon hizmet/urun/paket kaleminde personel_id = personel
 *   b) bu salondaki randevu_hizmetler'de personel_id = personel
 *   c) musteri_portfoy.olusturan_personel_id = personel (eski kod bunu docblock'ta
 *      vaat edip uygulamiyordu → personel kendi ekledigi musteriyi goremiyordu)
 *
 * Eskiden farki: id listesi PHP'ye cekilmez (binlerce id → whereIn); kosul SQL alt
 * sorgusu olarak eklenir.
 */
class PortfoyKapsami
{
    public function __construct(private YetkiServisi $yetki) {}

    /**
     * Kisit uygulanacak personel id'si; kisit yoksa null.
     * (tum_portfoy_gor varsa ya da kullanicinin bu salonda personel kaydi yoksa kisit yok.)
     */
    public function kisitliPersonel(int $yetkiliId, int $salonId): ?int
    {
        if ($this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'musteri.tum_portfoy_gor')) {
            return null;
        }

        return $this->personelId($yetkiliId, $salonId);
    }

    /** Yetkilinin bu salondaki personel kaydi (salon_personelleri.id); yoksa null. */
    public function personelId(int $yetkiliId, int $salonId): ?int
    {
        $pid = DB::table('salon_personelleri')->where('yetkili_id', $yetkiliId)->where('salon_id', $salonId)->value('id');

        return $pid ? (int) $pid : null;
    }

    /**
     * musteri_portfoy (alias $mp) sorgusuna kendi-portfoy kosulunu ekler.
     */
    public function uygula(Builder $q, int $salonId, ?int $personelId, string $mp = 'mp'): Builder
    {
        if ($personelId === null) {
            return $q;
        }

        return $q->where(function ($w) use ($salonId, $personelId, $mp) {
            $w->where("$mp.olusturan_personel_id", $personelId)
                ->orWhereIn("$mp.user_id", fn ($s) => $s->select('a.user_id')->from('adisyonlar as a')
                    ->where('a.salon_id', $salonId)
                    ->where(function ($k) use ($personelId) {
                        // UNION yerine uc IN (MariaDB'de parantezli UNION alt sorgusu surume bagli).
                        foreach (['adisyon_hizmetler', 'adisyon_urunler', 'adisyon_paketler'] as $tablo) {
                            $k->orWhereIn('a.id', fn ($t) => $t->select('adisyon_id')->from($tablo)->where('personel_id', $personelId));
                        }
                    }))
                ->orWhereIn("$mp.user_id", fn ($s) => $s->select('r.user_id')->from('randevular as r')
                    ->join('randevu_hizmetler as rh', 'rh.randevu_id', '=', 'r.id')
                    ->where('r.salon_id', $salonId)->where('rh.personel_id', $personelId));
        });
    }
}
