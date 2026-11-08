<?php

namespace App\Services\Satis;

use App\Domain\Satis\MobilPaketSeansi;
use App\Domain\Stok\HareketTipi;
use App\Exceptions\BulunamadiException;
use App\Models\AdisyonHizmetler;
use App\Models\Adisyonlar;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\Hizmetler;
use App\Models\Paketler;
use App\Models\RandevuHizmetler;
use App\Services\Paket\PaketSurumu;
use App\Services\Stok\ReceteUygulamaServisi;
use App\Services\Stok\StokHareketServisi;
use App\Services\Yetki\SalonSahipligi;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mobil kalem yazma — eski `ApiController@adisyonhizmetekle / adisyonurunekle / adisyonpaketekle /
 * tahsilat_*_sil` ile ayni satirlar ve tutarlar: istemci fiyati aynen yazilir, ayni gun odemesiz
 * adisyona birlestirilir, duzenlemede stok ve recete yeniden uygulanir. Web'in `KalemServisi` kurali
 * degismez. Eskiden farklar yalniz guvenlik: kimlik token'dan, musteri/personel/urun/paket salona
 * ait olmali, cagri basina idempotency_key.
 */
class MobilKalemServisi
{
    private const SIL_TAHSILAT = [
        'hizmet' => ['tahsilat_hizmetler', 'adisyon_hizmet_id'],
        'urun'   => ['tahsilat_urunler', 'adisyon_urun_id'],
        'paket'  => ['tahsilat_paketler', 'adisyon_paket_id'],
    ];

    public function __construct(
        private SalonSahipligi $sahiplik,
        private StokHareketServisi $stok,
        private ReceteUygulamaServisi $recete,
        private PaketSurumu $surum,
    ) {}

    public function hizmetKaydet(int $salonId, array $g, int $yetkiliId): array
    {
        $personelId = $this->personelOlmali($g['adisyonhizmetpersonelleriyeni'] ?? null, $salonId);
        $kalemId = self::idGirdisi($g['adisyon_hizmet_id'] ?? null);
        $sonuc = Idempotency::calistir('mobil_hizmet', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            function () use ($salonId, $g, $yetkiliId, $personelId, $kalemId) {
                $bugun = date('Y-m-d');
                $adisyonId = $this->adisyonCoz($salonId, $g, $bugun, $bugun, $yetkiliId);
                $k = $kalemId ? $this->kalemBul(AdisyonHizmetler::class, $kalemId, $salonId) : new AdisyonHizmetler();
                $k->adisyon_id = $adisyonId;
                $k->hizmet_id = $g['adisyonhizmetleriyeni'] ?? null;
                $k->islem_tarihi = date('Y-m-d', (int) strtotime((string) ($g['islemtarihiyeni'] ?? '')));
                $k->islem_saati = date('H:i:s', (int) strtotime((string) ($g['islemsaatiyeni'] ?? '')));
                $k->sure = $g['adisyonhizmetsuresi'] ?? null;
                $k->fiyat = $g['adisyonhizmetfiyati'] ?? null;
                $k->personel_id = $personelId;
                $k->geldi = true;
                $seans = AdisyonHizmetler::seansNormal($g['hizmetseanssayisi'] ?? null);
                $k->seans_sayisi = $seans;
                if (! $kalemId) {
                    $k->bekleyen_seans = $seans;
                }
                $k->save();
                $this->receteUygula($salonId, (int) $k->hizmet_id, $k->id, $personelId);
                AktiviteLog::yaz($salonId, $yetkiliId, $kalemId ? 'satis_hizmet_guncelle' : 'satis_hizmet_ekle', 'adisyon_hizmet', (int) $k->id,
                    'Tutar: '.($g['adisyonhizmetfiyati'] ?? ''), $kalemId ? 'Hizmet satışı güncellendi' : 'Hizmet satışı eklendi', ['adisyon_id' => $adisyonId]);

                return ['kalem_id' => (int) $k->id];
            });

        return $this->hizmetCevabi((int) $sonuc['kalem_id']);
    }

    public function urunKaydet(int $salonId, array $g, int $yetkiliId): array
    {
        $personelId = $this->personelOlmali($g['urun_satici'] ?? null, $salonId);
        $urun = DB::table('urunler')->where('id', $g['urunyeni'] ?? 0)->where('salon_id', $salonId)->first();
        if (! $urun) {
            throw new BulunamadiException('Ürün bu işletmeye ait değil');
        }
        $kalemId = self::idGirdisi($g['adisyon_urun_id'] ?? null);
        $sonuc = Idempotency::calistir('mobil_urun', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            function () use ($salonId, $g, $yetkiliId, $personelId, $kalemId, $urun) {
                $tarih = $g['urun_satis_tarihi'] ?? null;
                $adisyonId = $this->adisyonCoz($salonId, $g, date('Y-m-d', (int) strtotime((string) $tarih)), $tarih, $yetkiliId);
                $k = $kalemId ? $this->kalemBul(AdisyonUrunler::class, $kalemId, $salonId) : new AdisyonUrunler();
                $k->islem_tarihi = $tarih;
                $k->adisyon_id = $adisyonId;
                $k->urun_id = $urun->id;
                $k->personel_id = $personelId;
                $k->adet = $g['urun_adedi'] ?? null;
                $k->fiyat = $g['urun_fiyati'] ?? null;
                $k->save();
                $this->stokYaz(fn () => $this->stok->kaydet([
                    'salon_id'           => (int) $urun->salon_id,
                    'urun_id'            => (int) $urun->id,
                    'depo_id'            => $urun->varsayilan_depo_id,
                    'miktar'             => abs((float) ($g['urun_adedi'] ?? 0)),
                    'tip'                => HareketTipi::Satis,
                    'referans_tip'       => 'adisyon_urun',
                    'referans_id'        => (int) $k->id,
                    'birim_satis_fiyati' => $g['urun_fiyati'] ?? null,
                    'birim_alis_fiyati'  => $urun->alis_fiyati ?? null,
                    'aciklama'           => 'Adisyon urun satisi',
                    'kullanici_id'       => $yetkiliId,
                ]), (int) $k->id);
                AktiviteLog::yaz($salonId, $yetkiliId, $kalemId ? 'satis_urun_guncelle' : 'satis_urun_ekle', 'adisyon_urun', (int) $k->id,
                    'Tutar: '.($g['urun_fiyati'] ?? ''), $kalemId ? 'Ürün satışı güncellendi' : 'Ürün satışı eklendi', ['adisyon_id' => $adisyonId]);

                return ['kalem_id' => (int) $k->id];
            });

        return $this->urunCevabi((int) $sonuc['kalem_id']);
    }

    public function paketKaydet(int $salonId, array $g, int $yetkiliId): array
    {
        $personelId = $this->personelOlmali($g['personel_id'] ?? null, $salonId);
        $paket = Paketler::where('id', $g['paketid'] ?? 0)->where('salon_id', $salonId)->first();
        if (! $paket) {
            throw new BulunamadiException('Paket bu işletmeye ait değil');
        }
        $kalemId = self::idGirdisi($g['adisyon_paket_id'] ?? null);
        $secilen = $g['paket_hizmetleri'] ?? null;
        $this->eklenenHizmetlerOlmali((int) $paket->id, $secilen, $salonId);
        $sonuc = Idempotency::calistir('mobil_paket', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            function () use ($salonId, $g, $yetkiliId, $personelId, $kalemId, $paket, $secilen) {
                $satisPaketId = $this->surum->satisPaketi((int) $paket->id, is_array($secilen) ? $secilen : null);
                $tarih = $g['paket_satis_tarihi'] ?? null;
                $adisyonId = $this->adisyonCoz($salonId, $g, date('Y-m-d', (int) strtotime((string) $tarih)), $tarih, $yetkiliId);
                $k = $kalemId ? $this->kalemBul(AdisyonPaketler::class, $kalemId, $salonId) : new AdisyonPaketler();
                $k->adisyon_id = $adisyonId;
                $k->paket_id = $satisPaketId;
                $k->fiyat = $g['paketfiyat'] ?? null;
                $k->baslangic_tarihi = $g['paketbaslangictarihi'] ?? null;
                $k->seans_araligi = $g['seansaralikgun'] ?? null;
                $k->personel_id = $personelId;
                $k->senet_id = null;
                $k->taksitli_tahsilat_id = null;
                if (! MobilPaketSeansi::sinirsizDuzenleme($k->exists, $k->sinirsiz, $k->getOriginal('paket_id'), $satisPaketId)) {
                    $seans = MobilPaketSeansi::etkin($g['seans_sayisi'] ?? null, $this->surum->varsayilanSeans($satisPaketId));
                    $k->seans_sayisi = $seans;
                    $k->kullanilan_seans = 0;
                    $k->bekleyen_seans = $seans;
                    $k->kullanilmayan_seans = 0;
                }
                $k->otomatik_randevu_olusturuldu = false;
                $k->save();
                AktiviteLog::yaz($salonId, $yetkiliId, $kalemId ? 'satis_paket_guncelle' : 'satis_paket_ekle', 'adisyon_paket', (int) $k->id,
                    $paket->paket_adi.' - Tutar: '.($g['paketfiyat'] ?? ''), $kalemId ? 'Paket satışı güncellendi' : 'Paket satışı eklendi', ['adisyon_id' => $adisyonId]);

                return ['kalem_id' => (int) $k->id];
            });

        return $this->paketCevabi((int) $sonuc['kalem_id']);
    }

    /**
     * Kalem kaldirma: {basarili:'1'|'0', mesaj}. Tahsilatli kalem silinmez (eski cevapla ayni, hata degil).
     * Paket seanslarinin randevulari silinir, adisyonda kalem kalmadiysa adisyon da silinir.
     */
    public function sil(string $tip, int $kalemId, int $salonId, int $yetkiliId): array
    {
        [$tahTablo, $tahFk] = self::SIL_TAHSILAT[$tip];

        return DB::transaction(function () use ($tip, $kalemId, $salonId, $yetkiliId, $tahTablo, $tahFk) {
            $model = match ($tip) {
                'hizmet' => AdisyonHizmetler::class,
                'urun'   => AdisyonUrunler::class,
                default  => AdisyonPaketler::class,
            };
            $k = $this->kalemBul($model, $kalemId, $salonId);
            $adisyonId = (int) $k->adisyon_id;
            $ad = $this->kalemAdi($tip, $k);
            $tahsilatli = DB::table($tahTablo)->where($tahFk, $kalemId)
                ->whereIn('tahsilat_id', DB::table('tahsilatlar')->where('adisyon_id', $adisyonId)->select('id'))->exists();
            if ($tahsilatli) {
                return ['basarili' => '0', 'mesaj' => $ad.' için tahsilat kaydı bulunmakta olduğundan adisyondan silme işlemi gerçekleştirilemiyor. Önce tahsilat kaydının kaldırılması gereklidir.'];
            }

            if ($tip === 'urun') {
                $this->urunIade($k);
            }
            $k->delete();
            if ($tip === 'paket') {
                $this->paketSeansRandevulariniSil($kalemId);
                $this->bosAdisyonuSil($adisyonId);
            }
            AktiviteLog::yaz($salonId, $yetkiliId, 'satis_'.$tip.'_sil', 'adisyon_'.$tip, $kalemId, $ad,
                match ($tip) { 'hizmet' => 'Satıştan hizmet kaldırıldı', 'urun' => 'Satıştan ürün kaldırıldı', default => 'Satıştan paket kaldırıldı' },
                ['adisyon_id' => $adisyonId]);

            return ['basarili' => '1', 'mesaj' => match ($tip) {
                'hizmet' => $ad.' başarıyla kaldırıldı',
                'urun'   => $ad.' başarıyla kaldırıldı.',
                default  => $ad.' tahsilat ekranından başarıyla kaldırıldı',
            }];
        });
    }

    /** Eski kod `$adisyonurun->urun->urun` okur: urunler'de bu alan yok, ad hep bos gelir. */
    private function kalemAdi(string $tip, $k): string
    {
        return match ($tip) {
            'hizmet' => (string) (Hizmetler::where('id', $k->hizmet_id)->value('hizmet_adi') ?? ''),
            'urun'   => '',
            default  => (string) (Paketler::where('id', $k->paket_id)->value('paket_adi') ?? ''),
        };
    }

    private function urunIade(AdisyonUrunler $k): void
    {
        $urun = DB::table('urunler')->where('id', $k->urun_id)->first();
        if (! $urun) {
            return;
        }
        $this->stok->kaydet([
            'salon_id'     => (int) $urun->salon_id,
            'urun_id'      => (int) $urun->id,
            'depo_id'      => $urun->varsayilan_depo_id,
            'miktar'       => abs((float) $k->adet),
            'tip'          => HareketTipi::Iade,
            'referans_tip' => 'adisyon_urun_sil',
            'aciklama'     => 'Adisyondan urun silindi (iade)',
        ]);
    }

    private function paketSeansRandevulariniSil(int $kalemId): void
    {
        $randevuIdler = DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $kalemId)
            ->whereNotNull('randevu_id')->pluck('randevu_id')->unique()->all();
        foreach (RandevuHizmetler::whereIn('randevu_id', $randevuIdler)->get() as $rh) {
            $rh->delete();
        }
        DB::table('randevular')->whereIn('id', $randevuIdler)->delete();
    }

    private function bosAdisyonuSil(int $adisyonId): void
    {
        $kalan = AdisyonHizmetler::where('adisyon_id', $adisyonId)->count()
            + AdisyonUrunler::where('adisyon_id', $adisyonId)->count()
            + AdisyonPaketler::where('adisyon_id', $adisyonId)->count();
        if ($kalan === 0) {
            Adisyonlar::where('id', $adisyonId)->delete();
        }
    }

    /** Verilen adisyon (salona ait olmali); yoksa ayni gun odemesiz adisyon; o da yoksa yeni adisyon. */
    private function adisyonCoz(int $salonId, array $g, string $birlesmeTarihi, mixed $yeniTarih, int $yetkiliId): int
    {
        $verilen = self::idGirdisi($g['adisyon_id'] ?? null);
        if ($verilen) {
            if (! Adisyonlar::where('id', $verilen)->where('salon_id', $salonId)->exists()) {
                throw new BulunamadiException('Adisyon bulunamadı');
            }

            return $verilen;
        }
        $musteriId = (int) ($g['musteri_id'] ?? 0);
        $this->sahiplik->musteriOlmali($musteriId, $salonId);
        $mevcut = $this->ayniGunOdemesizAdisyon($musteriId, $salonId, $birlesmeTarihi);
        if ($mevcut) {
            return $mevcut;
        }

        return (int) Adisyonlar::ac($salonId, $musteriId, $yetkiliId, $yeniTarih === null ? null : (string) $yeniTarih)->id;
    }

    /** Ayni tarihli, hic kalem tahsilati almamis en yeni adisyon (eski SATIS-BIRLESTIR kurali). */
    private function ayniGunOdemesizAdisyon(int $musteriId, int $salonId, string $tarih): ?int
    {
        $q = DB::table('adisyonlar')->where('user_id', $musteriId)->where('salon_id', $salonId)->whereDate('tarih', $tarih);
        foreach ([
            ['tahsilat_hizmetler', 'adisyon_hizmetler', 'adisyon_hizmet_id'],
            ['tahsilat_paketler', 'adisyon_paketler', 'adisyon_paket_id'],
            ['tahsilat_urunler', 'adisyon_urunler', 'adisyon_urun_id'],
        ] as [$tahsilat, $kalem, $fk]) {
            $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from($tahsilat)
                ->join($kalem.' as k2', 'k2.id', '=', $tahsilat.'.'.$fk)
                ->whereColumn('k2.adisyon_id', 'adisyonlar.id'));
        }
        $id = $q->orderByDesc('id')->value('id');

        return $id ? (int) $id : null;
    }

    /** Kalem bu salonun adisyonunda olmali; yoksa baska salonun satiri da "bulunamadi" olur. */
    private function kalemBul(string $model, int $kalemId, int $salonId)
    {
        $k = $model::where('id', $kalemId)->first();
        if (! $k || ! Adisyonlar::where('id', $k->adisyon_id)->where('salon_id', $salonId)->exists()) {
            throw new BulunamadiException('Kalem bulunamadı');
        }

        return $k;
    }

    private function personelOlmali(mixed $girdi, int $salonId): ?int
    {
        $id = self::idGirdisi($girdi);
        $this->sahiplik->personelOlmali($id, $salonId);

        return $id;
    }

    /** Eski PaketSurumu kurali: yalniz katalogda olmayip yeni eklenen hizmet salona ait olmali. */
    private function eklenenHizmetlerOlmali(int $paketId, mixed $secilen, int $salonId): void
    {
        $this->sahiplik->hizmetOlmali($this->surum->eklenenHizmetler($paketId, (array) $secilen), $salonId);
    }

    private function receteUygula(int $salonId, int $hizmetId, int $kalemId, ?int $personelId): void
    {
        $this->stokYaz(fn () => $this->recete->uygula($salonId, $hizmetId, 'islem_recete', null, $personelId, 'personel'), $kalemId);
    }

    /** Stok ve recete hatasi satisi engellemez (eskiyle ayni). */
    private function stokYaz(callable $yaz, int $kalemId): void
    {
        try {
            $yaz();
        } catch (\Throwable $e) {
            Log::error('Mobil satis stok hareketi yazilamadi', ['kalem_id' => $kalemId, 'hata' => $e->getMessage()]);
        }
    }

    private static function idGirdisi(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : ((int) $v ?: null);
    }

    /** Eski `with(['hizmet','personel','cihaz'])` cevabi; personelde yalniz ekranin okudugu alanlar (prim, maas gitmez). */
    private function hizmetCevabi(int $id): array
    {
        $k = AdisyonHizmetler::findOrFail($id);

        return $k->toArray() + [
            'hizmet'   => $this->satir('hizmetler', $k->hizmet_id, ['id', 'hizmet_adi']),
            'personel' => $this->personel($k->personel_id),
            'cihaz'    => $this->satir('cihazlar', $k->cihaz_id, ['id', 'cihaz_adi']),
        ];
    }

    private function urunCevabi(int $id): array
    {
        $k = AdisyonUrunler::findOrFail($id);

        return $k->toArray() + [
            'urun'     => $this->satir('urunler', $k->urun_id, ['id', 'urun_adi', 'fiyat']),
            'personel' => $this->personel($k->personel_id),
        ];
    }

    private function paketCevabi(int $id): array
    {
        $k = AdisyonPaketler::findOrFail($id);

        return $k->toArray() + [
            'paket'    => $this->satir('paketler', $k->paket_id, ['*']),
            'personel' => $this->personel($k->personel_id),
        ];
    }

    private function personel(mixed $id): ?array
    {
        return $this->satir('salon_personelleri', $id, ['id', 'personel_adi', 'salon_id']);
    }

    private function satir(string $tablo, mixed $id, array $kolonlar): ?array
    {
        if (! $id) {
            return null;
        }
        $s = DB::table($tablo)->where('id', $id)->first($kolonlar);

        return $s ? (array) $s : null;
    }
}
