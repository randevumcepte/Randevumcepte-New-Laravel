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
        $tahsilatlar = Tahsilat::where('adisyon_id', $adisyonId)->get();

        // Saf hesap icin kalemleri normalize et (tip + fiyat + indirim + adet + hediye).
        $kalemler = [];
        foreach ($hizmetler as $h) {
            $kalemler[] = ['tip' => 'hizmet', 'fiyat' => $h->fiyat, 'indirim' => $h->indirim_tutari, 'adet' => 1, 'hediye' => (bool) $h->hediye];
        }
        foreach ($paketler as $p) {
            $kalemler[] = ['tip' => 'paket', 'fiyat' => $p->fiyat, 'indirim' => $p->indirim_tutari, 'adet' => 1, 'hediye' => (bool) $p->hediye];
        }
        foreach ($urunler as $u) {
            $kalemler[] = ['tip' => 'urun', 'fiyat' => $u->fiyat, 'indirim' => $u->indirim_tutari, 'adet' => $u->adet, 'hediye' => (bool) $u->hediye];
        }

        $ozet = AdisyonHesap::ozet($kalemler, $tahsilatlar->pluck('tutar')->all());
        $ozet['not'] = 'net/kalan GECICI formul — shadow ile dogrulanacak; odenen kesin.';

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
