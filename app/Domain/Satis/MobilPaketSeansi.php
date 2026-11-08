<?php

namespace App\Domain\Satis;

/** Mobil paket satisi seans kurali — SAF. Eski `ApiController@paketsatisiekleguncelle` ile ayni. */
final class MobilPaketSeansi
{
    /**
     * Girilen seans (bos degilse) esas; bos ise satis varsayilani (PaketTutari::satisSeansi — bolge basina hak,
     * web ile ayni). Eski mobil burada hizmet seans TOPLAMINI yaziyordu (8+8+8 = her bolgeye 24 hak); duzeltildi.
     */
    public static function etkin(mixed $girilen, int $varsayilan): int
    {
        return $girilen !== null && $girilen !== '' ? (int) $girilen : $varsayilan;
    }

    /** Ayni sinirsiz paketin satisi duzenleniyorsa adet alanlari teknik degerdir, yazilmaz. */
    public static function sinirsizDuzenleme(bool $kayitVar, mixed $sinirsiz, mixed $eskiPaketId, mixed $yeniPaketId): bool
    {
        return $kayitVar && ! empty($sinirsiz) && (int) $eskiPaketId === (int) $yeniPaketId;
    }
}
