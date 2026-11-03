<?php

namespace App\Services\Pazarlama;

use App\Domain\Ozet\OzetHesap;
use App\Domain\Rapor\RaporDonemi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carkifelek DONEM OZETI — eski StoreAdminController::dashboardCark ile birebir
 * (cevrim / kazanan / bos, tip dagilimi, son 3 kazanan, kupon kullanim orani).
 * Tablolar yoksa (kurulmamis salon modulu) sifir ozet. Hicbir sey YAZMAZ.
 */
class CarkOzetServisi
{
    private const LOG = 'carkifelek_cevirme_loglari';
    private const ODUL = 'carkifelek_odulleri';

    public function ozet(int $salonId, RaporDonemi $d): array
    {
        $bos = ['toplam_cevrim' => 0, 'kazanan' => 0, 'bos' => 0, 'kazanma_orani' => 0.0, 'tip_dagilim' => [],
            'son_kazananlar' => [], 'kupon' => ['verilen' => 0, 'kullanilan' => 0, 'orani' => 0.0], 'donem' => $d->dizi()];
        if (! Schema::hasTable(self::LOG)) {
            return $bos;
        }

        $aralik = [$d->basSaat(), $d->bitSaat()];
        $log = fn () => DB::table(self::LOG.' as l')->where('l.salon_id', $salonId)->whereBetween('l.created_at', $aralik);

        $k = $log()->selectRaw("COUNT(*) as toplam, SUM(l.tip != 'bos') as kazanan")->first();
        $toplam = (int) $k->toplam;
        $kazanan = (int) $k->kazanan;

        $kupon = $bos['kupon'];
        if (Schema::hasTable(self::ODUL)) {
            $o = DB::table(self::ODUL)->where('salon_id', $salonId)->whereBetween('created_at', $aralik)
                ->selectRaw('COUNT(*) as verilen, SUM(kullanildi = 1) as kullanilan')->first();
            $kupon = ['verilen' => (int) $o->verilen, 'kullanilan' => (int) $o->kullanilan,
                'orani' => OzetHesap::oran((int) $o->kullanilan, (int) $o->verilen)];
        }

        return [
            'toplam_cevrim' => $toplam,
            'kazanan'       => $kazanan,
            'bos'           => $toplam - $kazanan,
            'kazanma_orani' => OzetHesap::oran($kazanan, $toplam),
            'tip_dagilim'   => $log()->where('l.tip', '!=', 'bos')->groupBy('l.tip')
                ->selectRaw('l.tip, COUNT(*) as adet')->get()
                ->map(fn ($t) => ['tip' => $t->tip, 'adet' => (int) $t->adet])->all(),
            'son_kazananlar' => $log()->where('l.tip', '!=', 'bos')
                ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
                ->orderByDesc('l.id')->limit(3)
                ->get(['u.name', 'l.dilim_ismi', 'l.tip', 'l.created_at'])
                ->map(fn ($r) => [
                    'ad'    => trim(explode(' ', trim((string) $r->name))[0]) ?: 'Müşteri',
                    'odul'  => $r->dilim_ismi ?: ($r->tip ?: 'Ödül'),
                    'tarih' => $r->created_at,
                ])->all(),
            'kupon' => $kupon,
            'donem' => $d->dizi(),
        ];
    }
}
