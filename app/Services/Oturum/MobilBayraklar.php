<?php

namespace App\Services\Oturum;

use App\Domain\Oturum\TokenTipi;
use Illuminate\Http\Request;

/**
 * Mobil bayrak kurali — TEK KAYNAK. config('oturum.mobil_bayraklari') alanlari yalniz imzali mobil token'la etkilidir;
 * diger her istekte controller'a ulasmadan silinir. Controller ve istek siniflari bayragi okumaya devam eder.
 * Kimlikli rotada tokenGuard, kimliksiz rotada MobilBayrakSuzgeci cagirir.
 */
final class MobilBayraklar
{
    private const MOBIL_TOKEN = 'mobil_token';

    /** @param  array<int,string>  $kapsam  dogrulanmis token'in imzali kapsami */
    public static function uygula(Request $istek, array $kapsam): void
    {
        if (TokenTipi::mobilMi($kapsam)) {
            $istek->attributes->set(self::MOBIL_TOKEN, true);

            return;
        }
        self::temizle($istek);
    }

    public static function temizle(Request $istek): void
    {
        foreach ((array) config('oturum.mobil_bayraklari') as $bayrak) {
            $istek->query->remove($bayrak);
            $istek->request->remove($bayrak);
            $istek->json()->remove($bayrak);
        }
    }

    public static function mobilToken(Request $istek): bool
    {
        return $istek->attributes->get(self::MOBIL_TOKEN) === true;
    }
}
