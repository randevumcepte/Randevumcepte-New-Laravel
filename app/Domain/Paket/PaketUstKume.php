<?php

namespace App\Domain\Paket;

/**
 * Paket yukseltme kurali — SAF. Eski StoreAdminController::paketYukseltmeAdaylari / paketYukselt (765f3853).
 *
 * Hedef paket, mevcut paketin TUM hizmetlerini kapsamali (ust kume) ve en az 1 EK hizmeti olmali; boylece
 * mevcut seans kayitlarinin (adisyon_paket_seanslar.hizmet_id) hepsi hedefte de gecerli kalir.
 */
final class PaketUstKume
{
    /** @param array<int,int|string|null> $mevcut @param array<int,int|string|null> $hedef */
    public static function adayMi(array $mevcut, array $hedef): bool
    {
        $m = self::kume($mevcut);
        $h = self::kume($hedef);

        return ! array_diff($m, $h) && count($h) > count($m);
    }

    /**
     * Hedefte olup mevcutta olmayan hizmetler.
     *
     * @return int[]
     */
    public static function ekHizmetler(array $mevcut, array $hedef): array
    {
        return array_values(array_diff(self::kume($hedef), self::kume($mevcut)));
    }

    /**
     * Yukseltme sonrasi bekleyen seans: kapasite (hizmet basina hak × hizmet sayisi) − acilmis seans; 0 altina dusmez.
     */
    public static function bekleyenSeans(int $seansPer, int $hizmetAdedi, int $acilanSeans): int
    {
        return max(0, $seansPer * max(1, $hizmetAdedi) - $acilanSeans);
    }

    /** Gosterim: kalan = fiyat − odenen, 0 altina dusmez. */
    public static function kalan(float $fiyat, float $odenen): float
    {
        return max(0.0, round($fiyat - $odenen, 2));
    }

    /** @return int[] */
    private static function kume(array $idler): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $idler), fn ($i) => $i > 0)));
    }
}
