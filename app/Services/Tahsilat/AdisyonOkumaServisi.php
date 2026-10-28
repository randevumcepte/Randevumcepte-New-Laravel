<?php

namespace App\Services\Tahsilat;

use App\Domain\Tahsilat\AdisyonHesap;
use App\Exceptions\BulunamadiException;
use App\Models\Adisyonlar;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\Tahsilat;
use Illuminate\Support\Facades\DB;

/**
 * Adisyon OKUMA servisi (Faz 1 — salt okuma, hicbir sey yazmaz).
 *
 * ODENEN KAYNAGI: eski MOBIL API (formatAdisyonFast) ile BIREBIR — yani kalem
 * tahsilat tablolari (tahsilat_hizmetler/urunler/paketler) toplami. Boylece
 * Flutter ekranlari v2'ye tasininca gosterilen rakam DEGISMEZ.
 *
 * NOT (mutabakat): parent 'tahsilatlar' tablosu ile kalem-split tablolari
 * gecmis veride ~%52 adisyonda CELISIYOR (shadow ile tespit edildi). "Tek
 * ledger" hedefi ileride parent'i kanonik yapip gecmisi reconcile etmekle
 * cozulecek AYRI bir istir; Faz 1 davranis degistirmez (mobil paritesi).
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

        // Gorüntü icin odeme kayitlari: parent 'tahsilatlar', komisyon geliri
        // (para_girisi=1) HARIC. (Eski mobil de parent satirlari listeler.)
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

        // ODENEN = kalem tahsilat tablolari toplami (mobil API ile BIREBIR).
        $odenenTl = self::kalemOdenenTl($adisyonId);

        $ozet = AdisyonHesap::ozet($kalemler, [$odenenTl]);
        $ozet['not'] = 'Odenen = kalem-split (mobil parite). net=Σ(fiyat−indirim). Shadow: tahsilat:shadow-adisyon.';

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

    /**
     * Kalem tahsilat tablolari (tahsilat_hizmetler/urunler/paketler) toplami — TL.
     * Eski mobil API'nin 'odenen'i ile birebir ayni kaynak.
     */
    public static function kalemOdenenTl(int $adisyonId): float
    {
        $hizmet = (float) DB::table('tahsilat_hizmetler as t')
            ->join('adisyon_hizmetler as a', 't.adisyon_hizmet_id', '=', 'a.id')
            ->where('a.adisyon_id', $adisyonId)
            ->selectRaw('COALESCE(SUM(t.tutar),0) s')->value('s');

        $urun = (float) DB::table('tahsilat_urunler as t')
            ->join('adisyon_urunler as a', 't.adisyon_urun_id', '=', 'a.id')
            ->where('a.adisyon_id', $adisyonId)
            ->selectRaw('COALESCE(SUM(t.tutar),0) s')->value('s');

        $paket = (float) DB::table('tahsilat_paketler as t')
            ->join('adisyon_paketler as a', 't.adisyon_paket_id', '=', 'a.id')
            ->where('a.adisyon_id', $adisyonId)
            ->selectRaw('COALESCE(SUM(t.tutar),0) s')->value('s');

        return $hizmet + $urun + $paket;
    }
}
