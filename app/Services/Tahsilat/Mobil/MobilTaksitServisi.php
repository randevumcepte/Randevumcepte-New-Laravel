<?php

namespace App\Services\Tahsilat\Mobil;

use App\Domain\Tahsilat\MobilTahsilatHesabi as Hesap;
use App\Exceptions\GecersizIslemException;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\Alacaklar;
use App\Models\TahsilatHizmetler;
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
 * Eski kodda paketli taksit hep 500'dur (`foreach adisyon_paket_id as $paket` metinde $paket['id'] okur). Paket dagitimi
 * da yanlis tabloya yazar ama oraya hic ulasilmaz. Burada satir yazilmadan reddedilir; karar GECIS listesinde.
 */
class MobilTaksitServisi
{
    public function __construct(private MobilKapsam $kapsam, private MobilTahsilatYazici $yazici) {}

    public function ekle(int $salonId, int $yetkiliId, array $g): array
    {
        $hizmetIdleri = (array) ($g['adisyon_hizmet_id'] ?? []);
        $urunIdleri = (array) ($g['adisyon_urun_id'] ?? []);
        $paketIdleri = (array) ($g['adisyon_paket_id'] ?? []);
        if ($paketIdleri) {
            throw new GecersizIslemException('Paket içeren satış taksitlendirilemedi.');
        }
        $this->kapsam->kalemlerOlmali($salonId, 'hizmet', [...$hizmetIdleri, ...array_column((array) ($g['adisyon_hizmetleri'] ?? []), 'id')]);
        $this->kapsam->kalemlerOlmali($salonId, 'urun', [...$urunIdleri, ...array_column((array) ($g['adisyon_urunleri'] ?? []), 'id')]);
        $this->kapsam->musteriOlmali($salonId, $g['tahsilat_musteri_id'] ?? null);

        $sonuc = Idempotency::calistir('mobil_taksit', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            fn () => $this->yaz($salonId, $yetkiliId, $g, $hizmetIdleri, $urunIdleri));

        if (! $sonuc['replay']) {
            AktiviteLog::yaz($salonId, $yetkiliId, 'taksit_ekle', 'taksitli_tahsilat', $sonuc['taksitli_tahsilat_id'], 'Vade: '.($g['vade'] ?? '').' - Tutar: '.($g['taksit_tutar'] ?? ''), 'Taksitli tahsilat oluşturuldu');
        }

        return $sonuc;
    }

    private function yaz(int $salonId, int $yetkiliId, array $g, array $hizmetIdleri, array $urunIdleri): array
    {
        MobilTahsilatServisi::satisTarihi($g, ['hizmet' => $hizmetIdleri, 'urun' => $urunIdleri, 'paket' => []]);

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
            + AdisyonUrunler::whereIn('id', $urunIdleri)->whereNull('indirim_tutari')->count();
        $indirim = Hesap::kalemBasinaIndirim($g['indirim_tutari'] ?? '0', $g['musteri_indirimi'] ?? '0', $kalemSayisi);

        $hizmetler = (array) ($g['adisyon_hizmetleri'] ?? []);
        $urunler = (array) ($g['adisyon_urunleri'] ?? []);
        $this->kalemleriBagla(AdisyonHizmetler::class, $hizmetler, $taksitId, $indirim);
        $this->kalemleriBagla(AdisyonUrunler::class, $urunler, $taksitId, $indirim);

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
            $this->payYaz(TahsilatHizmetler::class, 'adisyon_hizmet_id', $hizmetler, $indirim, $dagitim, (int) $tahsilat->id);
            $this->payYaz(TahsilatUrunler::class, 'adisyon_urun_id', $urunler, $indirim, $dagitim, (int) $tahsilat->id);
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

    /** @param  class-string  $model */
    private function payYaz(string $model, string $fk, array $kalemler, float $indirim, string $odeme, int $tahsilatId): void
    {
        if (! $kalemler) {
            return;
        }
        $paylar = Hesap::onOdemePaylari(array_column($kalemler, 'fiyat'), $indirim, $odeme);
        foreach (array_values($kalemler) as $i => $k) {
            $o = new $model();
            $o->{$fk} = $k['id'];
            $o->tahsilat_id = $tahsilatId;
            $o->tutar = $paylar[$i];
            $o->aciklama = 'Kısmi tahsilat payı';
            $o->save();
        }
    }
}
