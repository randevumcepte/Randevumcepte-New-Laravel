<?php

namespace App\Services\Tahsilat;

use App\Domain\Tahsilat\AdisyonHesap;
use App\Exceptions\BulunamadiException;
use App\Models\Adisyonlar;
use App\Models\Alacaklar;
use Illuminate\Support\Facades\DB;

/**
 * Planlanan alacak (receivable) servisi — Satış & Tahsilat ekranı "Alacak Oluştur".
 *
 * Eski alacakekleduzenle (StoreAdminController) ile aynı alanlar; EK OLARAK
 * adisyon_id bağlanır (eski bug: adisyon_id'siz alacak, adisyon_id INNER JOIN'li
 * alacakgetir listesinde görünmezdi → yetim). Müşteri adisyonun user_id'sinden
 * alınır (ekranda ayrı müşteri seçimi yok; tek adisyona bağlı alacak).
 *
 * Yalnızca alacaklar tablosuna TEK satır yazar (para hareketi YOK). Controller
 * flag + yetki (finans.alacak_yonet) + kapsam denetler.
 */
class AlacakServisi
{
    /**
     * AÇIK alacak sorgusu — TEK KURAL (Özet "Alacaklılar" sekmesi + karşılaştırma kartı;
     * mobil alacaklar_v2 ile aynı): tutar > 0 ve bağlı taksit/senet vadesi ÖDENMEMİŞ
     * (ödenen vade kalıntısı sayılmaz). Tarih sınırı çağıranda.
     */
    public static function acikSorgu(int $salonId): \Illuminate\Database\Query\Builder
    {
        return DB::table('alacaklar')
            ->where('alacaklar.salon_id', $salonId)
            ->where('alacaklar.tutar', '>', 0)
            ->whereRaw('NOT EXISTS (SELECT 1 FROM taksit_vadeleri tv WHERE tv.id = alacaklar.taksit_vade_id AND tv.odendi = 1)')
            ->whereRaw('NOT EXISTS (SELECT 1 FROM senet_vadeleri sv WHERE sv.id = alacaklar.senet_vade_id AND sv.odendi = 1)');
    }

    /**
     * Tahsilat alacağa karşılık alındı (eski: alacak satırında "Tahsil Et" → kalem olarak tahsilat).
     * Seçilen planlanan alacaklar vadesi en yakından başlayarak ödenen tutar kadar düşülür:
     * tamamı karşılanan silinir, kısmen karşılanan kalan tutara iner. Yalnız bu adisyonun
     * vadesiz (taksit/senet vadesine bağlı olmayan) alacakları — vade ödemesi vade modunda kapanır.
     * Çağıran tahsilat transaction'ı içinde çalışır.
     *
     * @param  array<int>  $alacakIdleri
     * @return array{kapanan:array<int>, azalan:array<int,float>}
     */
    public function tahsilatlaKapat(int $adisyonId, array $alacakIdleri, float $odenenTl): array
    {
        $sonuc = ['kapanan' => [], 'azalan' => []];
        $kalanKurus = AdisyonHesap::tlToKurus($odenenTl);
        $alacaklar = Alacaklar::where('adisyon_id', $adisyonId)
            ->whereIn('id', array_map('intval', $alacakIdleri))
            ->whereNull('taksit_vade_id')->whereNull('senet_vade_id')
            ->orderBy('planlanan_odeme_tarihi')->orderBy('id')
            ->lockForUpdate()->get();

        foreach ($alacaklar as $a) {
            if ($kalanKurus <= 0) {
                break;
            }
            $tutarKurus = AdisyonHesap::tlToKurus($a->tutar);
            if ($kalanKurus >= $tutarKurus) {
                $a->delete();
                $sonuc['kapanan'][] = (int) $a->id;
                $kalanKurus -= $tutarKurus;
            } else {
                $a->tutar = AdisyonHesap::kurusToTl($tutarKurus - $kalanKurus);
                $a->save();
                $sonuc['azalan'][(int) $a->id] = (float) $a->tutar;
                $kalanKurus = 0;
            }
        }

        return $sonuc;
    }

    /** Bu adisyon için planlanan alacakları listele (minimum alan). */
    public function listele(int $adisyonId): array
    {
        return Alacaklar::where('adisyon_id', $adisyonId)
            ->orderBy('planlanan_odeme_tarihi')
            ->get(['id', 'tutar', 'planlanan_odeme_tarihi', 'aciklama'])
            ->map(fn ($a) => [
                'id'                     => (int) $a->id,
                'tutar'                  => $a->tutar,
                'planlanan_odeme_tarihi' => $a->planlanan_odeme_tarihi,
                'aciklama'               => $a->aciklama,
            ])->all();
    }

    /**
     * Alacak oluştur. $opt: yetkili_id, salon_id, olusturan_id.
     *
     * @throws BulunamadiException
     */
    public function olustur(int $adisyonId, float $tutar, ?string $planlananTarih, ?string $aciklama, array $opt): array
    {
        return DB::transaction(function () use ($adisyonId, $tutar, $planlananTarih, $aciklama, $opt) {
            $adisyon = Adisyonlar::find($adisyonId);
            if (! $adisyon) {
                throw new BulunamadiException('Adisyon bulunamadı');
            }

            $a = new Alacaklar();
            $a->adisyon_id = $adisyonId;
            $a->user_id = $adisyon->user_id;              // müşteri (adisyondan)
            $a->salon_id = (int) ($opt['salon_id'] ?? $adisyon->salon_id);
            $a->tutar = $tutar;
            $a->aciklama = $aciklama ?: null;
            $a->planlanan_odeme_tarihi = $planlananTarih ?: null;
            $a->olusturan_id = $opt['olusturan_id'] ?? null;
            $a->save();

            return ['alacak_id' => (int) $a->id, 'adisyon_id' => $adisyonId, 'tutar' => $tutar];
        });
    }

    /** Alacağı düzenle (tutar / planlanan tarih / açıklama). */
    public function guncelle(int $alacakId, float $tutar, ?string $planlananTarih, ?string $aciklama): array
    {
        $a = Alacaklar::find($alacakId);
        if (! $a) {
            throw new BulunamadiException('Alacak bulunamadı');
        }
        $a->tutar = $tutar;
        $a->planlanan_odeme_tarihi = $planlananTarih ?: null;
        $a->aciklama = $aciklama ?: null;
        $a->save();

        return ['alacak_id' => $alacakId, 'adisyon_id' => (int) $a->adisyon_id, 'tutar' => $tutar];
    }

    /** Alacağın salon_id'si (kapsam denetimi için). */
    public function alacakSalonId(int $alacakId): ?int
    {
        $s = Alacaklar::where('id', $alacakId)->value('salon_id');

        return $s !== null ? (int) $s : null;
    }

    /** Alacak sil (iptal). */
    public function sil(int $alacakId): array
    {
        $a = Alacaklar::find($alacakId);
        if (! $a) {
            throw new BulunamadiException('Alacak bulunamadı');
        }
        $adisyonId = (int) $a->adisyon_id;
        $a->delete();

        return ['alacak_id' => $alacakId, 'adisyon_id' => $adisyonId];
    }
}
