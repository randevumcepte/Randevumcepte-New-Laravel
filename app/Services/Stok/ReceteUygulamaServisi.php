<?php

namespace App\Services\Stok;

use App\Domain\Stok\HareketTipi;
use App\Models\HizmetSarfRecetesi;
use App\Models\Urunler;

/**
 * Hizmet sarf reçetesini UYGULA — eski StokController::receteyiUygula ile aynı: hizmetin
 * aktif reçetesindeki her ürün için tek 'sarf' hareketi (ürün deposu, yoksa salon varsayılanı);
 * seans sayısından bağımsız, hizmet başına bir kez. Tanım CRUD'u Tanim\ReceteServisi'nde.
 */
class ReceteUygulamaServisi
{
    public function __construct(private StokHareketServisi $stok) {}

    /** @return int Yazılan sarf hareketi sayısı */
    public function uygula(int $salonId, int $hizmetId, string $refTip, ?int $refId, ?int $kullaniciId = null, ?string $kullaniciTipi = null): int
    {
        $receteler = HizmetSarfRecetesi::where('salon_id', $salonId)
            ->where('hizmet_id', $hizmetId)->where('hizmet_tipi', 'islem')->where('aktif', true)->get();
        if ($receteler->isEmpty()) {
            return 0;
        }

        $batch = $this->stok->yeniBatch();
        $sayi = 0;
        foreach ($receteler as $r) {
            $urun = Urunler::find($r->urun_id);
            if (! $urun) {
                continue;
            }
            $this->stok->kaydet([
                'salon_id'     => $salonId,
                'urun_id'      => (int) $urun->id,
                'depo_id'      => $this->stok->depoCoz($salonId, null, $urun->varsayilan_depo_id),
                'miktar'       => (float) $r->miktar,
                'tip'          => HareketTipi::Sarf,
                'referans_tip' => $refTip,
                'referans_id'  => $refId,
                'batch_uuid'   => $batch,
                'aciklama'     => "Hizmet ID:{$hizmetId} için otomatik sarf",
                'kullanici_id' => $kullaniciId,
                'kullanici_tipi' => $kullaniciTipi,
            ]);
            $sayi++;
        }

        return $sayi;
    }
}
