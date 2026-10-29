<?php

namespace App\Services\Satis;

use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Models\Adisyonlar;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use Illuminate\Support\Facades\DB;

/**
 * Satış kalem yönetimi (Satış & Tahsilat Düzenleme ekranı) — ekle / adet / sil.
 *
 * Eski sistemle BİREBİR kolon/fiyat semantiği:
 *   - hizmet.fiyat = salon_sunulan_hizmetler.baslangic_fiyat (satır fiyatı)
 *   - urun.fiyat   = urunler.fiyat (BİRİM) × adet  (SATIR TOPLAMI)
 *   - paket.fiyat  = SUM(paket_hizmetler.fiyat)  (yoksa paketler.fiyat)
 *
 * Eski sistemden GÜVENLİ farklar (tutarsızlık önleme):
 *   - Kaleme tahsilat yapılmışsa adet değiştirme / silme REDDEDİLİR (eski
 *     urunadetdegistir tahsili yok sayıp fiyatı ezebiliyordu).
 *   - Otomatik randevu/seans oluşturma ve stok hareketi bu sürümde YOK (ayrı adım).
 * Flag arkasında (controller kontrol eder); transaction + kilit.
 */
class KalemServisi
{
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
            $bugun = date('Y-m-d');
            $saat = date('H:i:s');

            if ($tip === 'hizmet') {
                $kat = DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('hizmet_id', $katalogId)->first();
                if (! $kat) {
                    throw new BulunamadiException('Hizmet bu salonun katalogunda yok');
                }
                $k = new AdisyonHizmetler();
                $k->adisyon_id = $adisyonId;
                $k->hizmet_id = $katalogId;
                $k->personel_id = $personelId;
                $k->fiyat = $kat->baslangic_fiyat;
                $k->sure = $kat->sure_dk;
                $k->islem_tarihi = $bugun;
                $k->islem_saati = $saat;
                $k->kullanilan_seans = 0;
                $k->kullanilmayan_seans = 0;
                $k->save();
                $yeniId = $k->id;
            } elseif ($tip === 'urun') {
                $u = DB::table('urunler')->where('id', $katalogId)->first();
                if (! $u) {
                    throw new BulunamadiException('Ürün bulunamadı');
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
            } elseif ($tip === 'paket') {
                $p = DB::table('paketler')->where('id', $katalogId)->first();
                if (! $p) {
                    throw new BulunamadiException('Paket bulunamadı');
                }
                $fiyat = (float) DB::table('paket_hizmetler')->where('paket_id', $katalogId)->sum('fiyat');
                $seans = (float) DB::table('paket_hizmetler')->where('paket_id', $katalogId)->sum('seans');
                if (! $fiyat) {
                    $fiyat = $p->fiyat;
                }
                if (! $seans) {
                    $seans = $p->miktar;
                }
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

    /** Ürün adedi güncelle (fiyat = birim × adet yeniden). Tahsilatı varsa reddet. */
    public function urunAdetGuncelle(int $kalemId, int $adet): array
    {
        return DB::transaction(function () use ($kalemId, $adet) {
            $k = AdisyonUrunler::where('id', $kalemId)->lockForUpdate()->first();
            if (! $k) {
                throw new BulunamadiException('Ürün kalemi bulunamadı');
            }
            $tah = (float) DB::table('tahsilat_urunler')->where('adisyon_urun_id', $kalemId)->sum('tutar');
            if ($tah > 0) {
                throw new CakismaException('Bu ürüne tahsilat yapılmış; adet değiştirmeden önce tahsilatı iade edin');
            }
            $birim = (float) DB::table('urunler')->where('id', $k->urun_id)->value('fiyat');
            $adet = max(1, $adet);
            $k->adet = $adet;
            $k->fiyat = round($birim * $adet, 2);
            $k->save();

            return ['kalem_id' => $kalemId, 'adet' => $adet, 'fiyat' => $k->fiyat, 'adisyon_id' => (int) $k->adisyon_id];
        });
    }

    /** Kalemi sil. Kaleme tahsilat yapılmışsa reddet. */
    public function sil(string $tip, int $kalemId): array
    {
        return DB::transaction(function () use ($tip, $kalemId) {
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
}
