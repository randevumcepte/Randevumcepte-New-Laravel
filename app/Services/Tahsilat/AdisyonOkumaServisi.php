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

        // KVKK veri minimizasyonu: ham model dokumu YOK. Yalnizca gereken alanlar.
        // Serbest-metin PII (tahsilat.notlar) ve gereksiz kimlik alanlari DONDURULMEZ.
        // Snake_case (Flutter model uyumu) → ileride ekran gecisi drop-in olur.
        return [
            'adisyon' => [
                'id'       => (int) $adisyon->id,
                'salon_id' => (int) $adisyon->salon_id,
                'user_id'  => $adisyon->user_id !== null ? (int) $adisyon->user_id : null,
                'tarih'    => $adisyon->tarih,
                'odendi'   => (int) ($adisyon->odendi ?? 0),
            ],
            'hizmetler' => $hizmetler->map(fn ($h) => [
                'id'            => (int) $h->id,
                'hizmet_id'     => $h->hizmet_id !== null ? (int) $h->hizmet_id : null,
                'personel_id'   => $h->personel_id !== null ? (int) $h->personel_id : null,
                'fiyat'         => $h->fiyat,
                'indirim_tutari'=> $h->indirim_tutari,
                'hediye'        => (int) ($h->hediye ?? 0),
                'islem_tarihi'  => $h->islem_tarihi,
                'islem_saati'   => $h->islem_saati,
                'seans_sayisi'  => $h->seans_sayisi,
            ])->values(),
            'urunler' => $urunler->map(fn ($u) => [
                'id'             => (int) $u->id,
                'urun_id'        => $u->urun_id !== null ? (int) $u->urun_id : null,
                'personel_id'    => $u->personel_id !== null ? (int) $u->personel_id : null,
                'adet'           => $u->adet,
                'fiyat'          => $u->fiyat,
                'indirim_tutari' => $u->indirim_tutari,
                'hediye'         => (int) ($u->hediye ?? 0),
            ])->values(),
            'paketler' => $paketler->map(fn ($p) => [
                'id'             => (int) $p->id,
                'paket_id'       => $p->paket_id !== null ? (int) $p->paket_id : null,
                'personel_id'    => $p->personel_id !== null ? (int) $p->personel_id : null,
                'fiyat'          => $p->fiyat,
                'indirim_tutari' => $p->indirim_tutari,
                'hediye'         => (int) ($p->hediye ?? 0),
            ])->values(),
            // Odeme listesi: minimal (tutar/yontem/tarih). user_id/olusturan/notlar YOK.
            'tahsilatlar' => $tahsilatlar->map(fn ($t) => [
                'id'               => (int) $t->id,
                'tutar'            => $t->tutar,
                'odeme_yontemi_id' => $t->odeme_yontemi_id !== null ? (int) $t->odeme_yontemi_id : null,
                'odeme_tarihi'     => $t->odeme_tarihi,
            ])->values(),
            'hesap' => $ozet,
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
