<?php

namespace App\Domain\SistemYonetim;

/** Rol kumeleri: rotadaki kapi (sistem.rol) ile ekrana giden izin bayragi ayni tanimi okur. Izleyici yazma kumelerinde yok. */
final class SistemYetki
{
    public const SUPER_ADMIN = 'super_admin';

    public const YONETIM = 'super_admin,yonetici';

    /** Gunluk isler ve KVKK verisi okuma (WhatsApp log/telefon). */
    public const ISLEM = 'super_admin,yonetici,destek';

    public static function izinli(SistemRolu $rol, string $roller): bool
    {
        return in_array($rol->value, explode(',', $roller), true);
    }
}
