<?php

namespace App\Services\Tahsilat;

use App\Exceptions\BulunamadiException;
use App\Exceptions\YetkisizErisimException;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\Tahsilat;
use App\Models\TahsilatHizmetler;
use App\Models\TahsilatPaketler;
use App\Models\TahsilatUrunler;
use Illuminate\Support\Facades\DB;

/**
 * Tahsilat İADE / SİLME — v2-yazılmış BASİT tahsilatlar için.
 *
 * Transaction içinde: kalem tahsilatları (tahsilat_hizmetler/urunler/paketler)
 * + komisyon gelir satırı + parent tahsilatlar silinir. Böylece eski mobil
 * (kalem) ve eski web (parent) ödeneni azalır → dual-write tutarlı kalır.
 *
 * GÜVENLİK/KAPSAM kararları (eski tahsilatkaldir'dan bilinçli FARKLAR):
 *   - adisyon kalem indirim_tutari/hediye SIFIRLANMAZ (eski blind null'lama
 *     standing indirimi bozuyordu; v2 yazma indirim uygulamadığı için gereksiz).
 *   - taksit/senet bağlantılı kalem varsa İADE REDDEDİLİR (o akış v2'de henüz yok;
 *     yanlış kısmi geri-alma yapmaktansa 422 döneriz).
 *   - komisyon masraf (Masraflar) satırı silinmez (eski de silmiyordu) → uyarı.
 */
class TahsilatSilServisi
{
    /**
     * @throws BulunamadiException|YetkisizErisimException
     */
    public function sil(int $tahsilatId, array $meta): array
    {
        return DB::transaction(function () use ($tahsilatId) {
            $t = Tahsilat::where('id', $tahsilatId)->lockForUpdate()->first();
            if (! $t) {
                throw new BulunamadiException('Tahsilat bulunamadı');
            }
            $adisyonId = (int) $t->adisyon_id;

            $hizmetTah = TahsilatHizmetler::where('tahsilat_id', $tahsilatId)->get();
            $urunTah   = TahsilatUrunler::where('tahsilat_id', $tahsilatId)->get();
            $paketTah  = TahsilatPaketler::where('tahsilat_id', $tahsilatId)->get();

            // Taksit/senet bağlantılı mı? Varsa v2 iadesi desteklenmiyor → reddet (rollback).
            $this->taksitSenetGuard($hizmetTah->pluck('adisyon_hizmet_id'), AdisyonHizmetler::class);
            $this->taksitSenetGuard($urunTah->pluck('adisyon_urun_id'), AdisyonUrunler::class);
            $this->taksitSenetGuard($paketTah->pluck('adisyon_paket_id'), AdisyonPaketler::class);

            $silinenKalem = 0;
            foreach ([$hizmetTah, $urunTah, $paketTah] as $koleksiyon) {
                foreach ($koleksiyon as $satir) {
                    $satir->delete();
                    $silinenKalem++;
                }
            }

            // Komisyon gelir satırı (notlar linki kesin) — varsa sil.
            $komSilindi = Tahsilat::where('adisyon_id', $adisyonId)
                ->where('para_girisi', 1)
                ->where('notlar', 'Komisyon Geliri (tahsilat #'.$tahsilatId.')')
                ->delete();

            // Parent tahsilat
            $t->delete();

            $uyarilar = [];
            if ($komSilindi > 0) {
                $uyarilar[] = 'Komisyon gelir satırı silindi; ilgili Masraf (Banka Ödemeleri) kaydı elle kontrol edilmeli.';
            }

            return [
                'silindi'       => true,
                'tahsilat_id'   => $tahsilatId,
                'adisyon_id'    => $adisyonId,
                'silinen_kalem' => $silinenKalem,
                'uyarilar'      => $uyarilar,
            ];
        });
    }

    /** İlgili kalemlerden herhangi biri taksit/senet bağlıysa → 422 (reddet). */
    private function taksitSenetGuard($kalemIds, string $model): void
    {
        $ids = collect($kalemIds)->filter()->all();
        if (empty($ids)) {
            return;
        }
        $bagli = $model::whereIn('id', $ids)
            ->where(function ($q) {
                $q->whereNotNull('taksitli_tahsilat_id')->orWhereNotNull('senet_id');
            })
            ->exists();
        if ($bagli) {
            throw new \App\Exceptions\CakismaException('Taksit/senet bağlantılı tahsilat iadesi v2\'de henüz desteklenmiyor');
        }
    }
}
