<?php

namespace App\Domain\Ayar;

use App\Exceptions\GecersizIslemException;

/**
 * 7 gunluk plani calisma + mola satirlarina ayirir ve saat sirasini denetler (saf).
 *
 * Varsayilan: iki yari da yazilir, calisilan gun / mola icin bitis > baslangic zorunlu.
 * $yalniz: yalniz o yari islenir, oburu ne denetlenir ne yazilir (null doner).
 * $geceDevri: sira denetlenmez (10:00-00:00); mobil ekran bu saatleri hep kaydedebildi.
 */
final class HaftalikPlan
{
    public const CALISMA = 'calisma';

    public const MOLA = 'mola';

    /**
     * @param  list<array>  $gunler
     * @return array{calisma:?list<array>, mola:?list<array>}
     */
    public static function satirlar(array $gunler, ?string $yalniz = null, bool $geceDevri = false): array
    {
        $calisma = $yalniz === self::MOLA ? null : [];
        $mola = $yalniz === self::CALISMA ? null : [];

        foreach ($gunler as $g) {
            $gun = (int) $g['haftanin_gunu'];
            $ad = HaftalikSaat::GUNLER[$gun];

            if ($calisma !== null) {
                $bas = HaftalikSaat::saat($g['baslangic_saati'] ?? null);
                $bit = HaftalikSaat::saat($g['bitis_saati'] ?? null);
                $calisiyor = ! empty($g['calisiyor']);
                if (! $geceDevri && $calisiyor && ! HaftalikSaat::sirali($bas, $bit)) {
                    throw new GecersizIslemException("{$ad}: çalışma bitiş saati başlangıçtan sonra olmalı.");
                }
                $calisma[] = ['haftanin_gunu' => $gun, 'calisiyor' => (int) $calisiyor, 'baslangic_saati' => $bas, 'bitis_saati' => $bit];
            }

            if ($mola !== null) {
                $bas = HaftalikSaat::saat($g['mola_baslangic_saati'] ?? null);
                $bit = HaftalikSaat::saat($g['mola_bitis_saati'] ?? null);
                $molaVar = ! empty($g['mola_var']);
                if (! $geceDevri && $molaVar && ! HaftalikSaat::sirali($bas, $bit)) {
                    throw new GecersizIslemException("{$ad}: mola bitiş saati başlangıçtan sonra olmalı.");
                }
                $mola[] = ['haftanin_gunu' => $gun, 'mola_var' => (int) $molaVar, 'baslangic_saati' => $bas, 'bitis_saati' => $bit];
            }
        }

        return ['calisma' => $calisma, 'mola' => $mola];
    }
}
