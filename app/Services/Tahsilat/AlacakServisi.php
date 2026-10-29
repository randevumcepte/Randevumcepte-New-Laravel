<?php

namespace App\Services\Tahsilat;

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
