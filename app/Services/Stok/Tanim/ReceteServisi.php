<?php

namespace App\Services\Stok\Tanim;

use App\Models\HizmetSarfRecetesi;
use App\Services\Stok\StokSunumu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Hizmet sarf receteleri (hizmet tamamlaninca otomatik dusulecek urun + miktar).
 * Filtre: hizmet_id (+ hizmet_tipi, varsayilan 'islem'). Urun ve hizmet salona ait olmali.
 */
class ReceteServisi extends TanimServisi
{
    public function kaynak(): string
    {
        return 'recete';
    }

    protected function logOneki(): string
    {
        return 'sarf_recete';
    }

    protected function model(): string
    {
        return HizmetSarfRecetesi::class;
    }

    protected function kurallar(): array
    {
        return [
            'hizmet_id'   => ['required', 'integer', 'min:1'],
            'hizmet_tipi' => ['sometimes', 'string', 'in:islem,paket'],
            'urun_id'     => ['required', 'integer', 'min:1'],
            'miktar'      => ['required', 'numeric', 'gt:0', 'max:999999'],
        ];
    }

    protected function sorgu(int $salonId, array $filtre): Builder
    {
        $q = parent::sorgu($salonId, $filtre)->with('urun:id,urun_adi,birim');
        if (! empty($filtre['hizmet_id'])) {
            $q->where('hizmet_id', (int) $filtre['hizmet_id'])->where('hizmet_tipi', $filtre['hizmet_tipi'] ?? 'islem');
        }

        return $q;
    }

    protected function hazirla(int $salonId, Model $m, array $veri): void
    {
        $m->hizmet_tipi = $m->hizmet_tipi ?: 'islem';
        $this->kapsam->aitOlmali('urun', $m->urun_id, $salonId);
        if ($m->hizmet_tipi === 'islem') {
            $this->kapsam->hizmetOlmali((int) $m->hizmet_id, $salonId);
        }
    }

    protected function sunum(Model $m): array
    {
        return StokSunumu::recete($m);
    }

    protected function etiket(Model $m): string
    {
        return 'Hizmet #'.$m->hizmet_id.' → Ürün #'.$m->urun_id;
    }
}
