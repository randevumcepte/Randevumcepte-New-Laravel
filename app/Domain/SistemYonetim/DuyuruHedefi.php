<?php

namespace App\Domain\SistemYonetim;

/** Duyuru hedefi: hedef_ids kolonunda ne tutulur ve nasil okunur (salon id'leri ya da il id'leri, JSON). */
final class DuyuruHedefi
{
    /**
     * Secime gore saklanacak id'ler; "hepsi" icin bos.
     *
     * @return list<int>
     */
    public static function idler(DuyuruHedefTipi $tip, array $salonIdleri, array $ilIdleri): array
    {
        return match ($tip) {
            DuyuruHedefTipi::Hepsi  => [],
            DuyuruHedefTipi::Secili => self::temizle($salonIdleri),
            DuyuruHedefTipi::Il     => self::temizle($ilIdleri),
        };
    }

    /** @return list<int> */
    public static function coz(?string $json): array
    {
        $idler = $json ? json_decode($json, true) : null;

        return is_array($idler) ? self::temizle($idler) : [];
    }

    /** @return list<int> */
    private static function temizle(array $idler): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $idler), fn (int $id) => $id > 0)));
    }
}
