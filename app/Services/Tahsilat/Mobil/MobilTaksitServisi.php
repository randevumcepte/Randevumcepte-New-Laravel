<?php

namespace App\Services\Tahsilat\Mobil;

use App\Domain\Tahsilat\MobilTahsilatHesabi as Hesap;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\Alacaklar;
use App\Models\TahsilatHizmetler;
use App\Models\TahsilatPaketler;
use App\Models\TahsilatUrunler;
use App\Models\TaksitVadeleri;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

/**
 * Eski mobil taksitekleguncelle. Mobil yalniz yeni taksit acar (taksitli_tahsilat_id, adisyon_id, vade idleri
 * gondermez): bu yol tasinmistir. Tek transaction: eskiden 500 sonrasi yarim kalan taksit, vade ve alacak satirlari
 * burada olusmaz.
 *
 * Hizmet, urun ve paket ayni kuralla taksitlendirilir (kullanici 2026-11-08): kalem basina indirim, taksite baglama ve on
 * odeme payi uc kalem turunde aynidir. Eski kodda paketli taksit hep 500'du ($paket['id'] id listesinde okunuyordu) ve paket
 * payi urun tablosuna yazilacakti; burada paket payi tahsilat_paketler'e yazilir.
 */
class MobilTaksitServisi
{
    public function __construct(private MobilKapsam $kapsam, private MobilTahsilatYazici $yazici) {}

    public function ekle(int $salonId, int $yetkiliId, array $g): array
    {
        $hizmetIdleri = (array) ($g['adisyon_hizmet_id'] ?? []);
        $urunIdleri = (array) ($g['adisyon_urun_id'] ?? []);
        $paketIdleri = (array) ($g['adisyon_paket_id'] ?? []);
        $this->kapsam->kalemlerOlmali($salonId, 'hizmet', [...$hizmetIdleri, ...array_column((array) ($g['adisyon_hizmetleri'] ?? []), 'id')]);
        $this->kapsam->kalemlerOlmali($salonId, 'urun', [...$urunIdleri, ...array_column((array) ($g['adisyon_urunleri'] ?? []), 'id')]);
        $this->kapsam->kalemlerOlmali($salonId, 'paket', [...$paketIdleri, ...array_column((array) ($g['adisyon_paketleri'] ?? []), 'id')]);
        $this->kapsam->musteriOlmali($salonId, $g['tahsilat_musteri_id'] ?? null);

        $sonuc = Idempotency::calistir('mobil_taksit', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            fn () => $this->yaz($salonId, $yetkiliId, $g, $hizmetIdleri, $urunIdleri, $paketIdleri));

        if (! $sonuc['replay']) {
            AktiviteLog::yaz($salonId, $yetkiliId, 'taksit_ekle', 'taksitli_tahsilat', $sonuc['taksitli_tahsilat_id'], 'Vade: '.($g['vade'] ?? '').' - Tutar: '.($g['taksit_tutar'] ?? ''), 'Taksitli tahsilat oluşturuldu');
        }

        return $sonuc;
    }

    private function yaz(int $salonId, int $yetkiliId, array $g, array $hizmetIdleri, array $urunIdleri, array $paketIdleri): array
    {
        MobilTahsilatServisi::satisTarihi($g, ['hizmet' => $hizmetIdleri, 'urun' => $urunIdleri, 'paket' => $paketIdleri]);

        $musteriId = $g['tahsilat_musteri_id'];
        $vade = $g['vade'] ?? 0;
        $taksitId = (int) DB::table('taksitli_tahsilatlar')->insertGetId([
            'user_id' => $musteriId, 'vade_sayisi' => $vade, 'salon_id' => $salonId, 'olusturan_id' => $yetkiliId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (Hesap::vadeTarihleri((string) ($g['vade_baslangic_tarihi'] ?? ''), $vade) as $tarih) {
            $v = new TaksitVadeleri();
            $v->taksitli_tahsilat_id = $taksitId;
            $v->vade_tarih = $tarih;
            $v->odendi = false;
            $v->tutar = Hesap::vadeTutari($g['taksit_tutar'] ?? '0', $vade);
            $v->save();

            $a = new Alacaklar();
            $a->salon_id = $salonId;
            $a->tutar = $v->tutar;
            $a->taksitli_tahsilat_id = $taksitId;
            $a->taksit_vade_id = $v->id;
            $a->planlanan_odeme_tarihi = $v->vade_tarih;
            $a->olusturan_id = $yetkiliId;
            $a->user_id = $musteriId;
            $a->save();
        }

        $kalemSayisi = AdisyonHizmetler::whereIn('id', $hizmetIdleri)->whereNull('indirim_tutari')->count()
            + AdisyonUrunler::whereIn('id', $urunIdleri)->whereNull('indirim_tutari')->count()
            + AdisyonPaketler::whereIn('id', $paketIdleri)->whereNull('indirim_tutari')->count();
        $indirim = Hesap::kalemBasinaIndirim($g['indirim_tutari'] ?? '0', $g['musteri_indirimi'] ?? '0', $kalemSayisi);

        $hizmetler = (array) ($g['adisyon_hizmetleri'] ?? []);
        $urunler = (array) ($g['adisyon_urunleri'] ?? []);
        $paketler = (array) ($g['adisyon_paketleri'] ?? []);
        $this->kalemleriBagla(AdisyonHizmetler::class, $hizmetler, $taksitId, $indirim);
        $this->kalemleriBagla(AdisyonUrunler::class, $urunler, $taksitId, $indirim);
        $this->kalemleriBagla(AdisyonPaketler::class, $paketler, $taksitId, $indirim);

        if (Hesap::sifirdanBuyukMu($g['indirimli_toplam_tahsilat_tutari'] ?? null)) {
            [$odeme, $komisyon] = Hesap::odemeAyir($g['indirimli_toplam_tahsilat_tutari'], $g['komisyon_tutari'] ?? '0');
            $satir = [
                'salon_id' => $salonId, 'yetkili_id' => $yetkiliId, 'adisyon_id' => null, 'musteri_id' => $musteriId,
                'tutar' => $odeme, 'komisyon' => $komisyon, 'odeme_tarihi' => $g['tahsilat_tarihi'] ?? null,
                'odeme_yontemi' => $g['odeme_yontemi'] ?? null, 'notlar' => $g['tahsilat_notlari'] ?? null,
            ];
            $tahsilat = $this->yazici->tahsilatYaz($satir);
            $this->yazici->komisyonYaz($tahsilat, $satir);

            $dagitim = Hesap::dagitimOdemesi($odeme);
            $this->paylariYaz([
                [TahsilatHizmetler::class, 'adisyon_hizmet_id', $hizmetler],
                [TahsilatUrunler::class, 'adisyon_urun_id', $urunler],
                [TahsilatPaketler::class, 'adisyon_paket_id', $paketler],
            ], $indirim, $dagitim, (int) $tahsilat->id);
        }

        return ['sonuc' => 'başarılı', 'taksitli_tahsilat_id' => $taksitId];
    }

    /** @param  class-string  $model */
    private function kalemleriBagla(string $model, array $kalemler, int $taksitId, float $indirim): void
    {
        foreach ($kalemler as $k) {
            $satir = $model::where('id', $k['id'])->first();
            if ($satir->senet_id === null && $satir->taksitli_tahsilat_id === null) {
                $satir->taksitli_tahsilat_id = $taksitId;
            }
            if ($satir->indirim_tutari === null) {
                $satir->indirim_tutari = $indirim;
            }
            $satir->save();
        }
    }

    /**
     * On odeme tum kalemlere (hizmet, urun, paket) birlikte, net fiyat oraninda dagitilir; paylarin toplami odemedir.
     * Eski kod her turu kendi icinde oranliyordu: karisik satista odeme her ture tamamen yazilir, toplam katlanirdi.
     *
     * @param  list<array{0: class-string, 1: string, 2: array}>  $gruplar  [odeme modeli, kalem kolonu, kalemler]
     */
    private function paylariYaz(array $gruplar, float $indirim, string $odeme, int $tahsilatId): void
    {
        $fiyatlar = [];
        foreach ($gruplar as [, , $kalemler]) {
            foreach ($kalemler as $k) {
                $fiyatlar[] = $k['fiyat'] ?? 0;
            }
        }
        if (! $fiyatlar) {
            return;
        }
        $paylar = Hesap::onOdemePaylari($fiyatlar, $indirim, $odeme);
        $i = 0;
        foreach ($gruplar as [$model, $fk, $kalemler]) {
            foreach ($kalemler as $k) {
                $o = new $model();
                $o->{$fk} = $k['id'];
                $o->tahsilat_id = $tahsilatId;
                $o->tutar = $paylar[$i++];
                $o->aciklama = 'Kısmi tahsilat payı';
                $o->save();
            }
        }
    }
}
