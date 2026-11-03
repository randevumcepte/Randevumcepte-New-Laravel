<?php

namespace App\Services\Stok\Tanim;

use App\Domain\Stok\Miktar;
use App\Exceptions\GecersizIslemException;
use App\Models\Depo;
use App\Services\Stok\StokSunumu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Depolar. Kurallar (eskiyle ayni): salon basina TEK varsayilan depo; varsayilan
 * depo ve icinde stok olan depo silinemez. Listede toplam_stok (onbellek toplami).
 */
class DepoServisi extends TanimServisi
{
    public function kaynak(): string
    {
        return 'depo';
    }

    protected function model(): string
    {
        return Depo::class;
    }

    protected function kurallar(): array
    {
        return [
            'depo_adi'   => ['required', 'string', 'max:120'],
            'aciklama'   => ['nullable', 'string', 'max:1000'],
            'varsayilan' => ['sometimes', 'boolean'],
        ];
    }

    protected function sorgu(int $salonId, array $filtre): Builder
    {
        return parent::sorgu($salonId, $filtre)->reorder()
            ->select('depolar.*')
            ->selectSub(fn ($q) => $q->from('urun_depo_stoklari')->selectRaw('COALESCE(SUM(stok),0)')->whereColumn('depo_id', 'depolar.id'), 'toplam_stok')
            ->orderByDesc('varsayilan')->orderBy('depo_adi');
    }

    protected function hazirla(int $salonId, Model $m, array $veri): void
    {
        if (! empty($veri['varsayilan'])) {
            Depo::where('salon_id', $salonId)->whereKeyNot($m->getKey() ?? 0)->update(['varsayilan' => 0]);
            $m->varsayilan = 1;
        } elseif (array_key_exists('varsayilan', $veri) && $m->getOriginal('varsayilan')) {
            throw new GecersizIslemException('Varsayılan depo kaldırılamaz; önce başka bir depoyu varsayılan yapın.');
        }
    }

    protected function silmedenOnce(Model $m): void
    {
        if ($m->varsayilan) {
            throw new GecersizIslemException('Varsayılan depo silinemez.');
        }
        $stoklu = DB::table('urun_depo_stoklari')->where('depo_id', $m->id)->where('stok', '>', Miktar::ESIK)->exists();
        if ($stoklu) {
            throw new GecersizIslemException('Bu depoda hâlâ stok var, önce transfer veya fire ile boşaltın.');
        }
    }

    protected function sunum(Model $m): array
    {
        return StokSunumu::depo($m);
    }

    protected function etiket(Model $m): string
    {
        return (string) $m->depo_adi;
    }
}
