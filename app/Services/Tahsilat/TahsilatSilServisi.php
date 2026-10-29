<?php

namespace App\Services\Tahsilat;

use App\Exceptions\BulunamadiException;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\SenetVadeleri;
use App\Models\Tahsilat;
use App\Models\TahsilatHizmetler;
use App\Models\TahsilatPaketler;
use App\Models\TahsilatUrunler;
use App\Models\TaksitVadeleri;
use Illuminate\Support\Facades\DB;

/**
 * Tahsilat İADE / SİLME — basit ve VADE (taksit/senet) tahsilatları için.
 *
 * Transaction içinde: kalem tahsilatları + komisyon gelir satırı + parent silinir;
 * VADE ödemesiyse etkilenen taksit/senet vadeleri 'odendi=0'a döndürülür (eski
 * tahsilatkaldir sezgiselinin aynası: ödenen tutar kadar vade geri alınır).
 *
 * SINIR (v1): Bölünmüş vade (kısmi ödeme sonrası replicate) tam geri birleştirilmez
 * — böyle bir durum varsa uyarı döner, elle kontrol gerekir. Standing indirim'e
 * dokunulmaz (v2 yazma indirim uygulamıyor).
 */
class TahsilatSilServisi
{
    /**
     * @throws BulunamadiException
     */
    public function sil(int $tahsilatId, array $meta): array
    {
        return DB::transaction(function () use ($tahsilatId) {
            $t = Tahsilat::where('id', $tahsilatId)->lockForUpdate()->first();
            if (! $t) {
                throw new BulunamadiException('Tahsilat bulunamadı');
            }
            $adisyonId = (int) $t->adisyon_id;
            $tahsilatTutar = (float) $t->tutar;

            $hizmetTah = TahsilatHizmetler::where('tahsilat_id', $tahsilatId)->get();
            $urunTah   = TahsilatUrunler::where('tahsilat_id', $tahsilatId)->get();
            $paketTah  = TahsilatPaketler::where('tahsilat_id', $tahsilatId)->get();

            // Etkilenen taksit/senet planları (SİLMEDEN ÖNCE kalemlerden topla).
            $taksitPlan = [];
            $senetPlan = [];
            foreach ([
                [AdisyonHizmetler::class, $hizmetTah, 'adisyon_hizmet_id'],
                [AdisyonUrunler::class, $urunTah, 'adisyon_urun_id'],
                [AdisyonPaketler::class, $paketTah, 'adisyon_paket_id'],
            ] as [$model, $tah, $fk]) {
                $ids = $tah->pluck($fk)->filter()->all();
                if (empty($ids)) {
                    continue;
                }
                foreach ($model::whereIn('id', $ids)->get(['taksitli_tahsilat_id', 'senet_id']) as $k) {
                    if ($k->taksitli_tahsilat_id) {
                        $taksitPlan[] = $k->taksitli_tahsilat_id;
                    }
                    if ($k->senet_id) {
                        $senetPlan[] = $k->senet_id;
                    }
                }
            }

            // Kalem tahsilatlarını sil.
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

            // Parent tahsilat.
            $t->delete();

            // VADE geri alma (ödenen tutar kadar vade → odendi=0).
            $vadeReset = $this->vadeleriGeriAl(TaksitVadeleri::class, 'taksitli_tahsilat_id', array_values(array_unique($taksitPlan)), $tahsilatTutar)
                + $this->vadeleriGeriAl(SenetVadeleri::class, 'senet_id', array_values(array_unique($senetPlan)), $tahsilatTutar);

            $uyarilar = [];
            if ($komSilindi > 0) {
                $uyarilar[] = 'Komisyon gelir satırı silindi; ilgili Masraf (Banka Ödemeleri) kaydı elle kontrol edilmeli.';
            }
            if ($vadeReset > 0) {
                $uyarilar[] = $vadeReset.' vade geri alındı (odendi=0). Kısmi ödemede bölünmüş vade varsa elle kontrol edilmeli.';
            }

            return [
                'silindi'          => true,
                'tahsilat_id'      => $tahsilatId,
                'adisyon_id'       => $adisyonId,
                'silinen_kalem'    => $silinenKalem,
                'vade_geri_alindi' => $vadeReset,
                'uyarilar'         => $uyarilar,
            ];
        });
    }

    /**
     * İlgili planların ÖDENMİŞ vadelerini, geri alınan tutara ulaşana kadar
     * (sıralı) odendi=0 yapar. Eski tahsilatkaldir sezgiselinin aynası.
     */
    private function vadeleriGeriAl(string $model, string $fk, array $planIds, float $tutar): int
    {
        if (empty($planIds) || $tutar <= 0) {
            return 0;
        }

        $sum = 0.0;
        $ids = [];
        foreach ($planIds as $pid) {
            foreach ($model::where($fk, $pid)->where('odendi', 1)->orderBy('id')->get() as $v) {
                $sum += (float) $v->tutar;
                $ids[] = $v->id;
                if (round($sum, 2) >= round($tutar, 2)) {
                    break 2;
                }
            }
        }

        if (! empty($ids)) {
            $model::whereIn('id', $ids)->update(['odendi' => 0]);
        }

        return count($ids);
    }
}
