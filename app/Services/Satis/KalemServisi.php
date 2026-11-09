<?php

namespace App\Services\Satis;

use App\Domain\Satis\AdisyonSatir;
use App\Domain\Stok\HareketTipi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Models\Adisyonlar;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Services\Paket\PaketSorgusu;
use App\Services\Paket\PaketSurumu;
use App\Services\Stok\ReceteUygulamaServisi;
use App\Services\Stok\StokHareketServisi;
use App\Services\Yetki\SalonSahipligi;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Satış kalem yönetimi (Satış & Tahsilat Düzenleme ekranı) — ekle / adet / sil.
 *
 * Eski sistemle BİREBİR kolon/fiyat semantiği:
 *   - hizmet.fiyat = salon_sunulan_hizmetler.baslangic_fiyat (satır fiyatı)
 *   - urun.fiyat   = urunler.fiyat (BİRİM) × adet  (SATIR TOPLAMI)
 *   - paket.fiyat  = etkin katalog fiyati (Domain\Paket\PaketTutari)
 *
 * Eski sistemden GÜVENLİ farklar (tutarsızlık önleme):
 *   - Kaleme tahsilat yapılmışsa silme REDDEDİLİR; adet/fiyat değişiminde net, tahsil
 *     edilenin altına inemez (eski urunadetdegistir tahsili yok sayıp fiyatı ezebiliyordu).
 *   - Otomatik randevu/seans oluşturma bu sürümde YOK (ayrı adım).
 * STOK (eskiyle aynı, TEK YER): ürün kalemi eklenince 'satis' (−adet), silinince 'iade'
 * (+adet) hareketi StokHareketServisi ile yazılır; adet değişince fark kadar satış/iade
 * (eski urunadetdegistir stoğa dokunmuyordu — tutarlılık için düzeltildi). Ürün satan
 * tüm v2 akışları (Yeni Satış, Satış & Tahsilat, Hızlı Satış, tek kalem) bu yoldan geçer.
 * SARF: hizmet kalemi eklenince aktif reçete uygulanır (eskiyle aynı: hata satışı engellemez,
 * hizmet silinince geri alınmaz).
 * Flag arkasında (controller kontrol eder); transaction + kilit.
 */
class KalemServisi
{
    public function __construct(
        private PaketSorgusu $paketler,
        private StokHareketServisi $stok,
        private ReceteUygulamaServisi $recete,
        private PaketSurumu $surum,
        private SalonSahipligi $sahiplik,
    ) {}

    /**
     * Randevu kalemini adisyona ekle (randevudan tahsilata): fiyat/saat/sure/kaynak randevudan
     * gelir, seans alanlari NULL = paket satisi degil (eski adisyon_hizmet_ekle ile ayni).
     * Transaction cagiranda; sarf hatasi satisi engellemez (ekle() ile ayni).
     */
    public function randevuKalemiEkle(int $adisyonId, int $salonId, int $randevuId, string $tarih, object $rh, ?int $kullaniciId): int
    {
        $k = new AdisyonHizmetler();
        $k->adisyon_id = $adisyonId;
        $k->randevu_id = $randevuId;
        $k->hizmet_id = (int) $rh->hizmet_id;
        $k->personel_id = $rh->personel_id;
        $k->cihaz_id = $rh->cihaz_id;
        $k->fiyat = $rh->fiyat;
        $k->sure = $rh->sure_dk;
        $k->islem_tarihi = $tarih;
        $k->islem_saati = $rh->saat;
        $k->geldi = true;
        $k->seans_sayisi = null;
        $k->bekleyen_seans = null;
        $k->kullanilan_seans = null;
        $k->kullanilmayan_seans = null;
        $k->save();
        try {
            $this->recete->uygula($salonId, (int) $rh->hizmet_id, 'adisyon_hizmet_recete', (int) $k->id, $kullaniciId);
        } catch (\Throwable $e) {
            Log::error('Sarf reçetesi uygulanamadı', ['adisyon_hizmet_id' => $k->id, 'hizmet_id' => $rh->hizmet_id, 'salon_id' => $salonId, 'hata' => $e->getMessage()]);
        }

        return (int) $k->id;
    }

    /** Katalogdan adisyona kalem ekle. */
    public function ekle(int $adisyonId, string $tip, int $katalogId, array $opt): array
    {
        return DB::transaction(function () use ($adisyonId, $tip, $katalogId, $opt) {
            $adisyon = Adisyonlar::find($adisyonId);
            if (! $adisyon) {
                throw new BulunamadiException('Adisyon bulunamadı');
            }
            $salonId = (int) $adisyon->salon_id;
            $personelId = isset($opt['personel_id']) ? (int) $opt['personel_id'] : null;
            $bugun = $opt['tarih'] ?? date('Y-m-d'); // islem tarihi (satis tarihi secilebilen akislar)
            $saat = date('H:i:s');

            if ($tip === 'hizmet') {
                // Aynı hizmet_id için birden fazla offering olabilir → katalogdaki
                // temsilciyle (en yüksek fiyat) tutarlı seç.
                $kat = DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('hizmet_id', $katalogId)->orderByDesc('baslangic_fiyat')->first();
                if (! $kat) {
                    throw new BulunamadiException('Hizmet bu salonun katalogunda yok');
                }
                // fiyat / seans opt'ta verilirse (on gorusme donusumu) o kullanilir — fiyat yetkisi cagri yerinde.
                // Seans: bos / <=1 → tekil hizmet (NULL); 2+ → seansli (eski "Yeni Satis" hizmet kurali).
                $seans = AdisyonHizmetler::seansNormal($opt['seans'] ?? null);
                $k = new AdisyonHizmetler();
                $k->adisyon_id = $adisyonId;
                $k->hizmet_id = $katalogId;
                $k->personel_id = $personelId;
                $k->fiyat = $opt['fiyat'] ?? $kat->baslangic_fiyat;
                $k->sure = $kat->sure_dk;
                $k->islem_tarihi = $bugun;
                $k->islem_saati = $saat;
                $k->kullanilan_seans = 0;
                $k->kullanilmayan_seans = 0;
                if ($seans !== null) {
                    $k->seans_sayisi = $seans;
                    $k->bekleyen_seans = $seans;
                }
                $k->save();
                $yeniId = $k->id;
                try {
                    $this->recete->uygula($salonId, $katalogId, 'adisyon_hizmet_recete', (int) $k->id, $opt['kullanici_id'] ?? null);
                } catch (\Throwable $e) {
                    Log::error('Sarf reçetesi uygulanamadı', ['adisyon_hizmet_id' => $k->id, 'hizmet_id' => $katalogId, 'salon_id' => $salonId, 'hata' => $e->getMessage()]);
                }
            } elseif ($tip === 'urun') {
                // Ürün bu salonun AKTİF ürünü olmalı (StokKapsam::aitOlmali ile aynı kural).
                $u = DB::table('urunler')->where('id', $katalogId)->where('salon_id', $salonId)->where('aktif', 1)->first();
                if (! $u) {
                    throw new BulunamadiException('Ürün bu işletmeye ait değil veya silinmiş');
                }
                $adet = max(1, (int) ($opt['adet'] ?? 1));
                $k = new AdisyonUrunler();
                $k->adisyon_id = $adisyonId;
                $k->urun_id = $katalogId;
                $k->personel_id = $personelId;
                $k->adet = $adet;
                // SATIR TOPLAMI: elle girildiyse o (fiyat yetkisi çağrı yerinde), yoksa birim × adet.
                $k->fiyat = isset($opt['fiyat']) ? round((float) $opt['fiyat'], 2) : round((float) $u->fiyat * $adet, 2);
                $k->islem_tarihi = $bugun;
                $k->save();
                $yeniId = $k->id;
                // opt: depo_id (yoksa ürün/salon varsayılanı), batch_uuid, aciklama, kullanici_id
                $this->stokHareketi($salonId, $u, $adet, HareketTipi::Satis, 'adisyon_urun', (int) $k->id,
                    $opt + ['birim_satis_fiyati' => round((float) $k->fiyat / $adet, 2)]);
            } elseif ($tip === 'paket') {
                // Etkin katalog fiyati + bolge basina varsayilan seans (PaketTutari); paket bu salonun aktif paketi olmali.
                // fiyat/seans opt'ta verilirse o kullanilir — fiyat yetkisi cagri yerinde (katalogFiyati ile).
                // opt['hizmetler']: satista secilen hizmetler; katalogdan farkliysa kalem o satisa ozel pasif kopyaya
                // baglanir (PaketSurumu) — Paket Yonetimi, Yeni Satis ve Adisyon Detayi AYNI yol.
                $katalog = $this->paketler->satisTutari($katalogId, $salonId);
                $hizmetler = self::hizmetSecimi($opt['hizmetler'] ?? null);
                $this->sahiplik->hizmetOlmali($this->surum->eklenenHizmetler($katalogId, $hizmetler), $salonId);
                $fiyat = $opt['fiyat'] ?? $this->paketFiyati($katalogId, $salonId, $hizmetler, $katalog['fiyat']);
                $satisPaketId = $this->surum->satisPaketi($katalogId, $hizmetler);
                $seans = isset($opt['seans']) && $opt['seans'] !== '' ? max(0, (int) $opt['seans']) : $this->surum->varsayilanSeans($satisPaketId);
                $k = new AdisyonPaketler();
                $k->adisyon_id = $adisyonId;
                $k->paket_id = $satisPaketId; // saving kancasi sinirsiz/sure alanlarini kopyalar
                $k->personel_id = $personelId;
                $k->fiyat = $fiyat;
                $k->seans_sayisi = $seans;
                $k->bekleyen_seans = $seans;
                $k->kullanilan_seans = 0;
                $k->kullanilmayan_seans = 0;
                $k->save();
                $yeniId = $k->id;
            } else {
                throw new BulunamadiException('Geçersiz kalem tipi');
            }

            return ['tip' => $tip, 'kalem_id' => (int) $yeniId, 'adisyon_id' => $adisyonId];
        });
    }

    /** Ürün adedi güncelle (satırın mevcut birim fiyatı × yeni adet — elle fiyat korunur; stok farkı). Net, tahsil edilenin altına inemez. */
    public function urunAdetGuncelle(int $kalemId, int $adet, ?int $kullaniciId = null): array
    {
        return DB::transaction(function () use ($kalemId, $adet, $kullaniciId) {
            $k = AdisyonUrunler::where('id', $kalemId)->lockForUpdate()->first();
            if (! $k) {
                throw new BulunamadiException('Ürün kalemi bulunamadı');
            }
            $u = DB::table('urunler')->where('id', $k->urun_id)->first();
            $adet = max(1, $adet);
            $fark = $adet - (int) $k->adet;
            $birim = (int) $k->adet > 0 ? (float) $k->fiyat / (int) $k->adet : (float) ($u->fiyat ?? 0);
            $yeniFiyat = round($birim * $adet, 2);
            $this->tahsilAltSiniri($k, 'tahsilat_urunler', 'adisyon_urun_id', $yeniFiyat);
            $k->adet = $adet;
            $k->fiyat = $yeniFiyat;
            $k->save();
            if ($u && $fark !== 0) {
                $salonId = (int) Adisyonlar::where('id', $k->adisyon_id)->value('salon_id');
                $this->stokHareketi($salonId, $u, abs($fark), $fark > 0 ? HareketTipi::Satis : HareketTipi::Iade,
                    'adisyon_urun', $kalemId, ['kullanici_id' => $kullaniciId, 'aciklama' => 'Adisyon ürün adedi değişti']);
            }

            return ['kalem_id' => $kalemId, 'adet' => $adet, 'fiyat' => $k->fiyat, 'adisyon_id' => (int) $k->adisyon_id];
        });
    }

    /** Kalemi sil (ürünse stoğa iade). Kaleme tahsilat yapılmışsa reddet. */
    public function sil(string $tip, int $kalemId, ?int $kullaniciId = null): array
    {
        return DB::transaction(function () use ($tip, $kalemId, $kullaniciId) {
            [$model, $tahTablo, $tahFk] = match ($tip) {
                'hizmet' => [AdisyonHizmetler::class, 'tahsilat_hizmetler', 'adisyon_hizmet_id'],
                'urun'   => [AdisyonUrunler::class, 'tahsilat_urunler', 'adisyon_urun_id'],
                'paket'  => [AdisyonPaketler::class, 'tahsilat_paketler', 'adisyon_paket_id'],
                default  => throw new BulunamadiException('Geçersiz kalem tipi'),
            };
            $k = $model::where('id', $kalemId)->lockForUpdate()->first();
            if (! $k) {
                throw new BulunamadiException('Kalem bulunamadı');
            }
            $adisyonId = (int) $k->adisyon_id;
            $tah = (float) DB::table($tahTablo)->where($tahFk, $kalemId)->sum('tutar');
            if ($tah > 0) {
                throw new CakismaException('Kaleme tahsilat yapılmış; önce tahsilatı iade edin');
            }
            if ($tip === 'paket') {
                DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $kalemId)->delete();
            }
            if ($tip === 'urun' && ($u = DB::table('urunler')->where('id', $k->urun_id)->first())) {
                $salonId = (int) Adisyonlar::where('id', $adisyonId)->value('salon_id');
                $this->stokHareketi($salonId, $u, (int) $k->adet, HareketTipi::Iade, 'adisyon_urun_sil', $kalemId,
                    ['kullanici_id' => $kullaniciId, 'aciklama' => 'Adisyondan ürün silindi']);
            }
            $k->delete();

            return ['tip' => $tip, 'kalem_id' => $kalemId, 'adisyon_id' => $adisyonId];
        });
    }

    /**
     * Katalog fiyatı (ürün: birim × adet; paket: seçilen hizmetlere göre) — "katalogdan farklı fiyat"
     * yetki kararı için tek kaynak.
     *
     * @param  array<int,int|string>|null  $hizmetler  paket satışında seçilen hizmetler (boş = katalog listesi)
     */
    public function katalogFiyati(string $tip, int $katalogId, int $salonId, int $adet = 1, ?array $hizmetler = null): float
    {
        return round(match ($tip) {
            'hizmet' => (float) DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('hizmet_id', $katalogId)->max('baslangic_fiyat'),
            'urun'   => (float) DB::table('urunler')->where('id', $katalogId)->where('salon_id', $salonId)->value('fiyat') * max(1, $adet),
            'paket'  => $this->paketFiyati($katalogId, $salonId, self::hizmetSecimi($hizmetler), $this->paketler->satisTutari($katalogId, $salonId)['fiyat']),
            default  => 0.0,
        }, 2);
    }

    /** Paket satış fiyatı: hizmet seçimi varsa seçime göre (hizmet bazlı fiyat yoksa null → katalog), yoksa katalog. */
    private function paketFiyati(int $paketId, int $salonId, ?array $hizmetler, float $katalogFiyat): float
    {
        return ($hizmetler ? $this->surum->secimFiyati($paketId, $salonId, $hizmetler) : null) ?? $katalogFiyat;
    }

    /** @return int[]|null  boş seçim null (katalog listesi) */
    private static function hizmetSecimi(mixed $hizmetler): ?array
    {
        $idler = array_values(array_unique(array_filter(array_map('intval', is_array($hizmetler) ? $hizmetler : []))));

        return $idler ?: null;
    }

    /**
     * Kalem fiyatını elle değiştir (eski hizmet/urun/pakettahsilattutaridegistir). Fiyat, ürün için
     * SATIR toplamıdır. İndirim düşülmüş net, tahsil edilen tutarın altına inemez.
     */
    public function fiyatGuncelle(string $tip, int $kalemId, float $fiyat, ?int $kullaniciId = null): array
    {
        [$model, $tahTablo, $tahFk, $adTablo, $adKolon, $katalogFk] = match ($tip) {
            'hizmet' => [AdisyonHizmetler::class, 'tahsilat_hizmetler', 'adisyon_hizmet_id', 'hizmetler', 'hizmet_adi', 'hizmet_id'],
            'urun'   => [AdisyonUrunler::class, 'tahsilat_urunler', 'adisyon_urun_id', 'urunler', 'urun_adi', 'urun_id'],
            'paket'  => [AdisyonPaketler::class, 'tahsilat_paketler', 'adisyon_paket_id', 'paketler', 'paket_adi', 'paket_id'],
            default  => throw new BulunamadiException('Geçersiz kalem tipi'),
        };
        $fiyat = round($fiyat, 2);

        $sonuc = DB::transaction(function () use ($model, $tahTablo, $tahFk, $katalogFk, $kalemId, $fiyat, $tip) {
            $k = $model::where('id', $kalemId)->lockForUpdate()->first();
            if (! $k) {
                throw new BulunamadiException('Kalem bulunamadı');
            }
            $this->tahsilAltSiniri($k, $tahTablo, $tahFk, $fiyat);
            $eski = (float) $k->fiyat;
            $k->fiyat = $fiyat;
            $k->save();

            return ['tip' => $tip, 'kalem_id' => $kalemId, 'eski_fiyat' => $eski, 'fiyat' => $fiyat, 'adisyon_id' => (int) $k->adisyon_id, 'katalog_id' => (int) $k->{$katalogFk}];
        });

        if ($sonuc['eski_fiyat'] !== $fiyat) {
            $ad = DB::table($adTablo)->where('id', $sonuc['katalog_id'])->value($adKolon) ?? ucfirst($tip).' #'.$sonuc['katalog_id'];
            AktiviteLog::yaz((int) Adisyonlar::where('id', $sonuc['adisyon_id'])->value('salon_id'), $kullaniciId,
                'adisyon_'.$tip.'_fiyat_degistir', 'adisyon_'.$tip, $kalemId,
                $ad.' — '.AdisyonSatir::trFormat($fiyat).' ₺', 'Adisyondaki kalem fiyatı değiştirildi',
                ['adisyon_id' => $sonuc['adisyon_id'], 'eski_fiyat' => $sonuc['eski_fiyat'], 'yeni_fiyat' => $fiyat]);
        }
        unset($sonuc['katalog_id']);

        return $sonuc;
    }

    /**
     * Hizmetin seans sayısını değiştir (eski hizmetseansdegistir): boş/≤1 tekil hizmet, 2+ paket
     * satışı. Randevudan gelen kalemde değişmez (orada seans paket satışı sayılmaz — seansliMi);
     * kullanılmış seansın altına inilemez.
     */
    public function hizmetSeansGuncelle(int $kalemId, $girilen): array
    {
        return DB::transaction(function () use ($kalemId, $girilen) {
            $k = AdisyonHizmetler::where('id', $kalemId)->lockForUpdate()->first();
            if (! $k) {
                throw new BulunamadiException('Kalem bulunamadı');
            }
            if ($k->randevu_id !== null) {
                throw new GecersizIslemException('Randevudan gelen hizmetin seans sayısı değiştirilemez');
            }
            $seans = AdisyonHizmetler::seansNormal($girilen);
            $kullanilan = (int) $k->kullanilan_seans + (int) $k->kullanilmayan_seans;
            if ((int) $seans < $kullanilan) {
                throw new CakismaException('Seans sayısı kullanılmış seansın ('.$kullanilan.') altına inemez');
            }
            $k->seans_sayisi = $seans;
            $k->bekleyen_seans = $seans === null ? null : $seans - $kullanilan;
            $k->save();

            return ['kalem_id' => $kalemId, 'seans_sayisi' => $seans, 'adisyon_id' => (int) $k->adisyon_id];
        });
    }

    /** Kalemin personelini (hizmet veren / satıcı) değiştir (eski adisyonhizmetpersonelguncelle; tahsilatlı kalemde de serbest). */
    public function personelGuncelle(string $tip, int $kalemId, ?int $personelId): array
    {
        $model = self::kalemModeli($tip);
        $k = $model ? $model::find($kalemId) : null;
        if (! $k) {
            throw new BulunamadiException('Kalem bulunamadı');
        }
        $k->personel_id = $personelId;
        $k->save();

        return ['tip' => $tip, 'kalem_id' => $kalemId, 'personel_id' => $personelId, 'adisyon_id' => (int) $k->adisyon_id];
    }

    /** İndirim düşülmüş yeni net, kaleme tahsil edilenin altına inemez (fiyat + adet + paket yükseltme ortak kuralı). */
    public function tahsilAltSiniri($k, string $tahTablo, string $tahFk, float $yeniFiyat): void
    {
        $tahsil = (float) DB::table($tahTablo)->where($tahFk, $k->id)->sum('tutar');
        if (round($yeniFiyat - (float) ($k->indirim_tutari ?? 0), 2) < round($tahsil, 2)) {
            throw new CakismaException('Tutar, tahsil edilen tutarın ('.AdisyonSatir::trFormat($tahsil).' ₺) altına inemez; önce tahsilatı iade edin');
        }
    }

    private static function kalemModeli(string $tip): ?string
    {
        return match ($tip) {
            'hizmet' => AdisyonHizmetler::class,
            'urun'   => AdisyonUrunler::class,
            'paket'  => AdisyonPaketler::class,
            default  => null,
        };
    }

    /** Bir kalemin ait olduğu adisyonun salon_id'si (kapsam denetimi için). */
    public function kalemSalonId(string $tip, int $kalemId): ?int
    {
        $model = self::kalemModeli($tip);
        if (! $model) {
            return null;
        }
        $adisyonId = $model::where('id', $kalemId)->value('adisyon_id');

        return $adisyonId ? (int) Adisyonlar::where('id', $adisyonId)->value('salon_id') : null;
    }

    /** Ürün kalemi stok hareketi — satış/iade TEK yazıcı (StokHareketServisi). */
    private function stokHareketi(int $salonId, object $urun, int $adet, HareketTipi $tip, string $refTip, int $refId, array $opt): void
    {
        $this->stok->kaydet([
            'salon_id'           => $salonId,
            'urun_id'            => (int) $urun->id,
            'depo_id'            => $this->stok->depoCoz($salonId, isset($opt['depo_id']) ? (int) $opt['depo_id'] : null, $urun->varsayilan_depo_id ?? null),
            'miktar'             => $adet,
            'tip'                => $tip,
            'referans_tip'       => $refTip,
            'referans_id'        => $refId,
            'batch_uuid'         => $opt['batch_uuid'] ?? null,
            'birim_satis_fiyati' => $opt['birim_satis_fiyati'] ?? $urun->fiyat ?? null,
            'birim_alis_fiyati'  => $urun->alis_fiyati ?? null,
            'aciklama'           => $opt['aciklama'] ?? 'Adisyon ürün satışı',
            'kullanici_id'       => $opt['kullanici_id'] ?? null,
        ]);
    }
}
