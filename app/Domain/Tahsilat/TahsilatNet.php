<?php

namespace App\Domain\Tahsilat;

/**
 * Tahsilatin NET tutari ve azaltma kurali — TEK saf tanim (DB yok). tahsilatlar.tutar komisyon dusulmus nettir:
 * net = max(0, brut - komisyon) (kurus-kesin). Yazma (TahsilatYazServisi) ve duzenlemede azaltma kapisi
 * (TahsilatController / HesapSahibiOnayi) ayni tanimi kullanir: brut ayni kalip komisyon artarsa da net duser.
 */
final class TahsilatNet
{
    /** net kurus = max(0, brut - komisyon) */
    public static function kurus(mixed $brut, mixed $komisyon = 0): int
    {
        return max(0, AdisyonHesap::tlToKurus($brut) - AdisyonHesap::tlToKurus($komisyon));
    }

    /**
     * Yeni net (yeniBrut - yeniKomisyon) eski netten (tahsilatlar.tutar) dusuk mu? Kurus-kesin.
     * $yeniBrut null = tamamen silme (azaltma).
     */
    public static function azaltmaMi(float $eskiNet, ?float $yeniBrut, float $yeniKomisyon = 0.0): bool
    {
        return $yeniBrut === null || self::kurus($yeniBrut, $yeniKomisyon) < AdisyonHesap::tlToKurus($eskiNet);
    }
}
