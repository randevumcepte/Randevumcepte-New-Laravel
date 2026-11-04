<?php

namespace App\Domain\Deneme;

/**
 * Yeni deneme salonunun varsayilan ayarlari (eski ApiController::demoHesapKur degerleri, saf veri).
 */
final class DemoSablonu
{
    private const SMS_AYAR_SAYISI = 23;

    /** Bu ayarlarda personele de SMS gider. */
    private const SMS_PERSONEL_ACIK = [1, 2, 3, 6, 7, 11, 12, 14];

    /** Bu ayarlarda musteriye SMS gitmez. */
    private const SMS_MUSTERI_KAPALI = [17, 20, 21, 22, 23];

    private const PAZAR = 7;

    /** @return list<array{haftanin_gunu:int,calisiyor:int,baslangic_saati:string,bitis_saati:string}> */
    public static function calismaSaatleri(): array
    {
        return array_map(fn (int $gun) => [
            'haftanin_gunu'   => $gun,
            'calisiyor'       => 1,
            'baslangic_saati' => $gun === self::PAZAR ? '00:00:00' : '07:00:00',
            'bitis_saati'     => $gun === self::PAZAR ? '00:00:00' : '20:00:00',
        ], range(1, self::PAZAR));
    }

    /** @return list<array{haftanin_gunu:int,mola_var:int,baslangic_saati:string,bitis_saati:string}> */
    public static function molaSaatleri(): array
    {
        return array_map(fn (int $gun) => [
            'haftanin_gunu' => $gun, 'mola_var' => 0, 'baslangic_saati' => '00:00:00', 'bitis_saati' => '00:00:00',
        ], range(1, self::PAZAR));
    }

    /** @return list<array{ayar_id:int,musteri:int,personel:int}> */
    public static function smsAyarlari(): array
    {
        return array_map(fn (int $ayar) => [
            'ayar_id'  => $ayar,
            'musteri'  => in_array($ayar, self::SMS_MUSTERI_KAPALI, true) ? 0 : 1,
            'personel' => in_array($ayar, self::SMS_PERSONEL_ACIK, true) ? 1 : 0,
        ], range(1, self::SMS_AYAR_SAYISI));
    }
}
