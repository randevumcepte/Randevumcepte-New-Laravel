<?php

namespace App\Domain\Randevu;

/**
 * Zaman cakismasi formulleri — SAF. Eski ApiController ile BIREBIR:
 *   ortusur()    = _kaynak_cakisma_tekil_api'deki pencere cakismasi
 *   araliktaMi() = saatAraliginda (sinirlar DAHIL; gece yarisini asan aralik destekli)
 */
final class Cakisma
{
    /** Yeni [ns,ne) ile mevcut [es,ee) pencere cakismasi. Saatler "H:i[:s]". */
    public static function ortusur(string $ns, string $ne, string $es, string $ee): bool
    {
        [$ns, $ne, $es, $ee] = array_map(fn ($s) => self::sn($s), [$ns, $ne, $es, $ee]);

        return ($ns < $ee && $ne > $es) || ($ns >= $es && $ns < $ee);
    }

    /** $saat, [$bas, $bit] araliginda mi (sinirlar dahil; bas > bit ise gece yarisini asar). */
    public static function araliktaMi(string $bas, string $bit, string $saat): bool
    {
        [$b, $e, $n] = array_map(fn ($s) => self::sn($s), [$bas, $bit, $saat]);
        if ($b > $e) {
            return $n >= $b || $n < $e;
        }

        return $n >= $b && $n <= $e;
    }

    private static function sn(string $s): int
    {
        $p = array_map('intval', explode(':', trim($s)) + [0, 0, 0]);

        return $p[0] * 3600 + $p[1] * 60 + ($p[2] ?? 0);
    }
}
