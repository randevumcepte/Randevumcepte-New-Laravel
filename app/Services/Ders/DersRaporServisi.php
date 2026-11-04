<?php

namespace App\Services\Ders;

use App\Domain\Ders\DersRaporu;
use App\Models\DersKatilimci;
use App\Models\DersOturumu;
use Illuminate\Support\Facades\DB;

/** Grup dersi raporu verisi; hesap saf Domain\Ders\DersRaporu'nda. */
class DersRaporServisi
{
    public function rapor(int $salonId, string $tarih1, string $tarih2): array
    {
        $oturumlar = DersOturumu::yayinda()->where('salon_id', $salonId)->whereBetween('tarih', [$tarih1, $tarih2])
            ->get(['id', 'kapasite', 'personel_id', 'ders_tipi']);
        $durumlar = DersKatilimci::whereIn('oturum_id', $oturumlar->pluck('id'))->get(['oturum_id', 'durum'])
            ->groupBy('oturum_id')->map(fn ($g) => $g->pluck('durum')->all())->all();
        $personeller = DB::table('salon_personelleri')->where('salon_id', $salonId)->pluck('personel_adi', 'id')->all();

        return ['tarih1' => $tarih1, 'tarih2' => $tarih2] + DersRaporu::hesapla($oturumlar, $durumlar, $personeller);
    }
}
