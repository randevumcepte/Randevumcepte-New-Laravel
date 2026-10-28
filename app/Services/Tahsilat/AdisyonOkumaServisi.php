<?php

namespace App\Services\Tahsilat;

use App\Domain\Tahsilat\AdisyonHesap;
use App\Exceptions\BulunamadiException;
use App\Models\Adisyonlar;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\Tahsilat;

/**
 * Adisyon OKUMA servisi (Faz 1 — salt okuma, hicbir sey yazmaz).
 *
 * Bir adisyonun basligini, kalemlerini (hizmet/urun/paket) ve tahsilatlarini
 * getirir; parasal ozeti AdisyonHesap (saf, kurus) ile hesaplar. net/kalan
 * formulu shadow ile dogrulanana kadar 'hesap.not' alaninda isaretlidir.
 */
class AdisyonOkumaServisi
{
    /** Yalnizca salon_id (kapsam denetimi icin ucuz sorgu). */
    public function salonIdGetir(int $adisyonId): ?int
    {
        $v = Adisyonlar::where('id', $adisyonId)->value('salon_id');

        return $v !== null ? (int) $v : null;
    }

    /**
     * @throws BulunamadiException
     */
    public function getir(int $adisyonId): array
    {
        $adisyon = Adisyonlar::find($adisyonId);
        if (! $adisyon) {
            throw new BulunamadiException('Adisyon bulunamadı');
        }

        $hizmetler = AdisyonHizmetler::where('adisyon_id', $adisyonId)->get();
        $urunler   = AdisyonUrunler::where('adisyon_id', $adisyonId)->get();
        $paketler  = AdisyonPaketler::where('adisyon_id', $adisyonId)->get();

        // Odeme satirlari: komisyon GELIRI (para_girisi=1) HARIC — kanonik 'odenen'.
        $tahsilatlar = Tahsilat::where('adisyon_id', $adisyonId)
            ->where(function ($q) {
                $q->where('para_girisi', 0)->orWhereNull('para_girisi');
            })
            ->get();

        // Saf hesap icin kalemleri normalize et. KANONIK: sadece fiyat + indirim.
        // (urun.fiyat zaten satir toplami; hediye kalemin fiyati zaten 0.)
        $kalemler = [];
        foreach ($hizmetler as $h) {
            $kalemler[] = ['fiyat' => $h->fiyat, 'indirim' => $h->indirim_tutari];
        }
        foreach ($paketler as $p) {
            $kalemler[] = ['fiyat' => $p->fiyat, 'indirim' => $p->indirim_tutari];
        }
        foreach ($urunler as $u) {
            $kalemler[] = ['fiyat' => $u->fiyat, 'indirim' => $u->indirim_tutari];
        }

        $ozet = AdisyonHesap::ozet($kalemler, $tahsilatlar->pluck('tutar')->all());
        $ozet['not'] = 'Kanonik API formulu (formatAdisyonFast) ile hizali; tahsilat:shadow-adisyon ile dogrulandi.';

        return [
            'adisyon' => [
                'id'            => (int) $adisyon->id,
                'salon_id'      => (int) $adisyon->salon_id,
                'user_id'       => $adisyon->user_id !== null ? (int) $adisyon->user_id : null,
                'tarih'         => $adisyon->tarih,
                'odendi'        => (int) ($adisyon->odendi ?? 0),
                'odendi_tarihi' => $adisyon->odendi_tarihi ?? null,
            ],
            'hizmetler' => $hizmetler,
            'urunler'   => $urunler,
            'paketler'  => $paketler,
            'tahsilatlar' => $tahsilatlar,
            'hesap'     => $ozet,
        ];
    }
}
