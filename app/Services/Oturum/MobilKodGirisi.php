<?php

namespace App\Services\Oturum;

use App\Exceptions\CakismaException;
use Illuminate\Http\Request;

/**
 * GECICI kural: mobil uygulamada onay / dogrulama kodu girisi yok (ekran degisikligi kullanici karari bekliyor).
 * Imzali mobil token'la gelen istekte islem kod gerektiriyorsa (SMS ayari 23 silmede hesap sahibi onayi, 16 seans
 * geldi) kod URETILMEZ, mesaj GONDERILMEZ; 409 + acik mesaj doner. Web akisi degismez. Mobilde kod girisi
 * eklenince bu sinif ve cagrilari kaldirilir.
 */
final class MobilKodGirisi
{
    public const MESAJ = 'Bu işlem için onay kodu gerekiyor; kod girişi mobil uygulamada henüz yok, işlemi web panelinden yapın.';

    /** @throws CakismaException istek imzali mobil token'dan geliyorsa */
    public static function reddet(?Request $istek = null): void
    {
        $istek ??= app()->bound('request') ? app('request') : null;
        if ($istek !== null && MobilBayraklar::mobilToken($istek)) {
            throw new CakismaException(self::MESAJ);
        }
    }
}
