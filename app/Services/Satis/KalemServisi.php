<?php

namespace App\Services\Satis;

use App\Domain\Stok\HareketTipi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Models\Adisyonlar;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Services\Paket\PaketSorgusu;
use App\Services\Stok\StokHareketServisi;
use Illuminate\Support\Facades\DB;

/**
 * Satış kalem yönetimi (Satış & Tahsilat Düzenleme ekranı) — ekle / adet / sil.
 *
 * Eski sistemle BİREBİR kolon/fiyat semantiği:
 *   - hizmet.fiyat = salon_sunulan_hizmetler.baslangic_fiyat (satır fiyatı)
 *   - urun.fiyat   = urunler.fiyat (BİRİM) × adet  (SATIR TOPLAMI)
 *   - paket.fiyat  = etkin katalog fiyati (Domain\Paket\PaketTutari)
 *
 * Eski sistemden GÜVENLİ farklar (tutarsızlık önleme):
 *   - Kaleme tahsilat yapılmışsa adet değiştirme / silme REDDEDİLİR (eski
 *     urunadetdegistir tahsili yok sayıp fiyatı ezebiliyordu).
 *   - Otomatik randevu/seans oluşturma bu sürümde YOK (ayrı adım).
 * STOK (eskiyle aynı, TEK YER): ürün kalemi eklenince 'satis' (−adet), silinince 'iade'
 * (+adet) hareketi StokHareketServisi ile yazılır; adet değişince fark kadar satış/iade
 * (eski urunadetdegistir stoğa dokunmuyordu — tutarlılık için düzeltildi). Ürün satan
 * tüm v2 akışları (Yeni Satış, Satış & Tahsilat, Hızlı Satış, tek kalem) bu yoldan geçer.
 * Flag arkasında (controller kontrol eder); transaction + kilit.
 */
class KalemServisi
{
    public function __construct(
        private PaketSorgusu $paketler,
        private StokHareketServisi $stok,
    ) {}

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
                $seans = isset($opt['seans']) && (int) $opt['seans'] > 1 ? (int) $opt['seans'] : null;
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
                $k->fiyat = round((float) $u->fiyat * $adet, 2); // SATIR TOPLAMI
                $k->islem_tarihi = $bugun;
                $k->save();
                $yeniId = $k->id;
                // opt: depo_id (yoksa ürün/salon varsayılanı), batch_uuid, aciklama, kullanici_id
                $this->stokHareketi($salonId, $u, $adet, HareketTipi::Satis, 'adisyon_urun', (int) $k->id, $opt);
            } elseif ($tip === 'paket') {
                // Etkin katalog fiyat/seansi (PaketTutari); paket bu salonun aktif paketi olmali.
                // fiyat/seans opt'ta verilirse (Paket Yonetimi satisi) o kullanilir — yetki cagri yerinde.
                $katalog = $this->paketler->satisTutari($katalogId, $salonId);
                $fiyat = $opt['fiyat'] ?? $katalog['fiyat'];
                $seans = $opt['seans'] ?? $katalog['seans'];
                $k = new AdisyonPaketler();
                $k->adisyon_id = $adisyonId;
                $k->paket_id = $katalogId;
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

    /** Ürün adedi güncelle (fiyat = birim × adet yeniden; stok farkı). Tahsilatı varsa reddet. */
    public function urunAdetGuncelle(int $kalemId, int $adet, ?int $kullaniciId = null): array
    {
        return DB::transaction(function () use ($kalemId, $adet, $kullaniciId) {
            $k = AdisyonUrunler::where('id', $kalemId)->lockForUpdate()->first();
            if (! $k) {
                throw new BulunamadiException('Ürün kalemi bulunamadı');
            }
            $tah = (float) DB::table('tahsilat_urunler')->where('adisyon_urun_id', $kalemId)->sum('tutar');
            if ($tah > 0) {
                throw new CakismaException('Bu ürüne tahsilat yapılmış; adet değiştirmeden önce tahsilatı iade edin');
            }
            $u = DB::table('urunler')->where('id', $k->urun_id)->first();
            $adet = max(1, $adet);
            $fark = $adet - (int) $k->adet;
            $k->adet = $adet;
            $k->fiyat = round((float) ($u->fiyat ?? 0) * $adet, 2);
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

    /** Bir kalemin ait olduğu adisyonun salon_id'si (kapsam denetimi için). */
    public function kalemSalonId(string $tip, int $kalemId): ?int
    {
        $model = match ($tip) {
            'hizmet' => AdisyonHizmetler::class,
            'urun'   => AdisyonUrunler::class,
            'paket'  => AdisyonPaketler::class,
            default  => null,
        };
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
            'birim_satis_fiyati' => $urun->fiyat ?? null,
            'birim_alis_fiyati'  => $urun->alis_fiyati ?? null,
            'aciklama'           => $opt['aciklama'] ?? 'Adisyon ürün satışı',
            'kullanici_id'       => $opt['kullanici_id'] ?? null,
        ]);
    }
}
