<?php

namespace App\Domain\SistemYonetim;

/** Ekranin hangi dugmeyi cizecegi. Asil kapi rotadaki sistem.rol: burasi yalniz gorunum. */
final class SalonIzinleri
{
    /** @return array{duzenle:bool,sahip_yap:bool,hesaba_gir:bool,not_yaz:bool} */
    public static function rolIcin(SistemRolu $rol): array
    {
        return [
            'duzenle'    => SistemYetki::izinli($rol, SistemYetki::YONETIM),
            'sahip_yap'  => SistemYetki::izinli($rol, SistemYetki::SUPER_ADMIN),
            'hesaba_gir' => SistemYetki::izinli($rol, SistemYetki::ISLEM),
            'not_yaz'    => SistemYetki::izinli($rol, SistemYetki::ISLEM),
        ];
    }
}
