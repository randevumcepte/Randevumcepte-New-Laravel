<?php

namespace App\Support;

use App\Exceptions\CakismaException;
use Illuminate\Http\Request;

/**
 * Randevu v2 yazma kapisi (kill-switch) — TEK KAYNAK. Genel acik ya da salon
 * pilot listesinde ise yazmaya izin verir (config/randevu.php).
 */
class RandevuYazma
{
    /** 409 mesaji — istemci (takvim ekrani) bu mesajla DRY-RUN onizlemeye duser. */
    public const KAPALI_MESAJ = 'Randevu v2 yazma şu an kapalı (yalnızca dry_run)';

    /**
     * Istek gercek yazma mi? dry_run VARSAYILAN true (plan). dry_run=false istenip
     * kapi kapaliysa 409 (CakismaException → ApiCevap zarfi; eski yanitla birebir).
     */
    public static function yazilacakMi(Request $request, int $salonId): bool
    {
        $yaz = ! $request->boolean('dry_run', true);
        if ($yaz && ! self::acik($salonId)) {
            throw new CakismaException(self::KAPALI_MESAJ);
        }

        return $yaz;
    }

    public static function acik(int $salonId): bool
    {
        return (bool) config('randevu.yazma_aktif')
            || in_array($salonId, (array) config('randevu.yazma_salonlari', []), true);
    }
}
