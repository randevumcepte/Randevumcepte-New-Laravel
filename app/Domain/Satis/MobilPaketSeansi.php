<?php

namespace App\Domain\Satis;

/** Mobil paket satisi seans kurali — SAF. Eski `ApiController@paketsatisiekleguncelle` ile ayni. */
final class MobilPaketSeansi
{
    /** Girilen seans (bos degilse) esas; bos ise katalogdaki hizmet seanslarinin toplami. */
    public static function etkin(mixed $girilen, int $katalogToplami): int
    {
        return $girilen !== null && $girilen !== '' ? (int) $girilen : $katalogToplami;
    }

    /** Ayni sinirsiz paketin satisi duzenleniyorsa adet alanlari teknik degerdir, yazilmaz. */
    public static function sinirsizDuzenleme(bool $kayitVar, mixed $sinirsiz, mixed $eskiPaketId, mixed $yeniPaketId): bool
    {
        return $kayitVar && ! empty($sinirsiz) && (int) $eskiPaketId === (int) $yeniPaketId;
    }
}
