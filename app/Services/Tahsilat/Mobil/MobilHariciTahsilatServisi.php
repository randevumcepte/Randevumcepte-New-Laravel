<?php

namespace App\Services\Tahsilat\Mobil;

use App\Domain\Tahsilat\MobilTahsilatHesabi as Hesap;
use App\Exceptions\GecersizIslemException;
use App\Models\AdisyonHizmetler;
use App\Models\Adisyonlar;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\TahsilatHizmetler;
use App\Models\TahsilatPaketler;
use App\Models\TahsilatUrunler;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

/**
 * Eski mobil harici tahsilat (gecmise donuk satis + tahsilat) ve salonun satis kalem katalogu. Yan etkisiz: recete, stok,
 * seans olusmaz. Katalog sorgusu telefonda satisla ortak (MobilHizliSatisServisi cagirir).
 */
class MobilHariciTahsilatServisi
{
    public function __construct(private MobilKapsam $kapsam, private MobilTahsilatYazici $yazici) {}

    /** @return array{hizmetler:mixed, urunler:mixed, paketler:mixed} */
    public function katalog(int $salonId): array
    {
        return [
            'hizmetler' => DB::table('salon_sunulan_hizmetler')->join('hizmetler', 'salon_sunulan_hizmetler.hizmet_id', '=', 'hizmetler.id')
                ->where('salon_sunulan_hizmetler.salon_id', $salonId)->where('salon_sunulan_hizmetler.aktif', true)
                ->select('hizmetler.id as id', 'hizmetler.hizmet_adi as ad',
                    DB::raw('COALESCE(salon_sunulan_hizmetler.son_fiyat, salon_sunulan_hizmetler.baslangic_fiyat, hizmetler.fiyat, 0) as fiyat'))
                ->orderBy('hizmetler.hizmet_adi')->get(),
            'urunler'  => DB::table('urunler')->where('salon_id', $salonId)->where('aktif', true)
                ->select('id', 'urun_adi as ad', 'fiyat')->orderBy('urun_adi')->get(),
            'paketler' => DB::table('paketler')->where('salon_id', $salonId)->where('aktif', 1)
                ->select('id', 'paket_adi as ad', 'fiyat')->orderBy('paket_adi')->get(),
        ];
    }

    public function ekle(int $salonId, int $yetkiliId, array $g): array
    {
        if (empty($g['musteri_id']) || empty($g['satis_tarihi'])) {
            throw new GecersizIslemException('Salon, musteri ve satis tarihi zorunludur.');
        }
        $kalemler = $g['kalemler'] ?? null;
        if (is_string($kalemler)) {
            $kalemler = json_decode($kalemler, true);
        }
        if (! is_array($kalemler) || count($kalemler) === 0) {
            throw new GecersizIslemException('En az bir satis kalemi ekleyin.');
        }
        $this->kapsam->musteriOlmali($salonId, $g['musteri_id']);
        $this->kapsam->katalogOlmali($salonId, $kalemler);

        $sonuc = Idempotency::calistir('mobil_harici_tahsilat', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            fn () => $this->yaz($salonId, $yetkiliId, $g, $kalemler));

        if (! $sonuc['replay']) {
            AktiviteLog::yaz($salonId, $yetkiliId, 'harici_tahsilat', 'tahsilat', $sonuc['adisyon_id'], (string) $sonuc['toplam'], 'Harici tahsilat/adisyon kaydedildi.');
        }

        return $sonuc;
    }

    private function yaz(int $salonId, int $yetkiliId, array $g, array $kalemler): array
    {
        $tarih = date('Y-m-d', strtotime((string) $g['satis_tarihi']));
        $musteriId = (int) $g['musteri_id'];
        $adisyon = Adisyonlar::ac($salonId, $musteriId, $yetkiliId, $tarih);
        $adisyon->harici = 1;
        $adisyon->save();

        $olusan = [];
        $toplam = 0.0;
        foreach ($kalemler as $k) {
            $tip = $k['tip'] ?? null;
            $itemId = $k['id'] ?? null;
            if (empty($itemId) || ! in_array($tip, ['hizmet', 'urun', 'paket'], true)) {
                continue;
            }
            $fiyat = Hesap::hariciFiyat($k['fiyat'] ?? 0);
            $olusan[] = ['tip' => $tip, 'id' => $this->kalemYaz($tip, (int) $adisyon->id, $itemId, $fiyat, $tarih, Hesap::hariciAdet($k['adet'] ?? 1)), 'fiyat' => $fiyat];
            $toplam += $fiyat;
        }
        if (! $olusan) {
            throw new GecersizIslemException('Gecerli satis kalemi bulunamadi.');
        }

        $tutar = Hesap::hariciTahsilatTutari($g['tahsilat_tutari'] ?? 0, $toplam);
        if ($tutar > 0) {
            $t = $this->yazici->tahsilatYaz([
                'salon_id' => $salonId, 'yetkili_id' => $yetkiliId, 'adisyon_id' => $adisyon->id, 'musteri_id' => $musteriId, 'tutar' => $tutar,
                'komisyon' => null, 'odeme_tarihi' => $tarih, 'odeme_yontemi' => $g['odeme_yontemi'] ?? null, 'notlar' => $g['not'] ?? null,
            ]);
            foreach ($olusan as $k) {
                $pay = Hesap::hariciPay($k['fiyat'], $toplam, $tutar);
                if ($pay <= 0) {
                    continue;
                }
                [$model, $fk] = match ($k['tip']) {
                    'hizmet' => [TahsilatHizmetler::class, 'adisyon_hizmet_id'],
                    'urun'   => [TahsilatUrunler::class, 'adisyon_urun_id'],
                    default  => [TahsilatPaketler::class, 'adisyon_paket_id'],
                };
                $o = new $model();
                $o->{$fk} = $k['id'];
                $o->tahsilat_id = $t->id;
                $o->tutar = $pay;
                $o->save();
            }
        }

        return ['durum' => 'basarili', 'mesaj' => 'Harici tahsilat kaydedildi.', 'adisyon_id' => (int) $adisyon->id, 'toplam' => $toplam];
    }

    private function kalemYaz(string $tip, int $adisyonId, mixed $itemId, float $fiyat, string $tarih, float $adet): int
    {
        if ($tip === 'hizmet') {
            $k = new AdisyonHizmetler();
            $k->hizmet_id = $itemId;
            $k->islem_tarihi = $tarih;
        } elseif ($tip === 'urun') {
            $k = new AdisyonUrunler();
            $k->urun_id = $itemId;
            $k->adet = $adet;
        } else {
            $k = new AdisyonPaketler();
            $k->paket_id = $itemId;
            $k->seans_sayisi = 0;
            $k->kullanilan_seans = 0;
            $k->bekleyen_seans = 0;
            $k->kullanilmayan_seans = 0;
            $k->otomatik_randevu_olusturuldu = false;
        }
        $k->adisyon_id = $adisyonId;
        $k->fiyat = $fiyat;
        $k->indirim_tutari = 0;
        $k->save();

        return (int) $k->id;
    }
}
