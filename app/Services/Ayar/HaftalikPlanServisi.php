<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\HaftalikSaat;
use App\Exceptions\GecersizIslemException;
use App\Support\HaftalikSaatDeposu;

/**
 * 7 gunluk calisma + mola PLANI — sahipten bagimsiz (salon, cihaz; personel ayni depoyu kullanir).
 *
 * Plan bicimi her yerde ayni (web + Flutter): {haftanin_gunu, gun_adi, calisiyor, baslangic_saati,
 * bitis_saati, mola_var, mola_baslangic_saati, mola_bitis_saati}. Kaydi olmayan gun varsayilanla
 * doner. Yazmada calisilan gun / mola icin bitis > baslangic zorunlu (eskide kontrol yoktu).
 */
class HaftalikPlanServisi
{
    private const VARSAYILAN = ['bas' => '09:00', 'bit' => '18:00', 'mola_bas' => '12:00', 'mola_bit' => '13:00'];

    /** @return list<array> 7 gun */
    public function oku(string $sahip, int $sahipId): array
    {
        $calisma = collect(HaftalikSaatDeposu::oku($sahip, $sahipId, HaftalikSaatDeposu::CALISMA))->keyBy('haftanin_gunu');
        $mola = collect(HaftalikSaatDeposu::oku($sahip, $sahipId, HaftalikSaatDeposu::MOLA))->keyBy('haftanin_gunu');

        $gunler = [];
        foreach (HaftalikSaat::GUNLER as $gun => $ad) {
            $c = $calisma->get($gun);
            $m = $mola->get($gun);
            $gunler[] = [
                'haftanin_gunu'        => $gun,
                'gun_adi'              => $ad,
                'calisiyor'            => (int) ($c['calisiyor'] ?? 0),
                'baslangic_saati'      => HaftalikSaat::saat($c['baslangic_saati'] ?? null) ?? self::VARSAYILAN['bas'],
                'bitis_saati'          => HaftalikSaat::saat($c['bitis_saati'] ?? null) ?? self::VARSAYILAN['bit'],
                'mola_var'             => (int) ($m['mola_var'] ?? 0),
                'mola_baslangic_saati' => HaftalikSaat::saat($m['baslangic_saati'] ?? null) ?? self::VARSAYILAN['mola_bas'],
                'mola_bitis_saati'     => HaftalikSaat::saat($m['bitis_saati'] ?? null) ?? self::VARSAYILAN['mola_bit'],
            ];
        }

        return $gunler;
    }

    /**
     * Dogrula + sil-ve-yaz (cagiran transaction acar). Donus: calisilan gun sayisi.
     *
     * @param  list<array>  $gunler  HaftalikPlanKurallari ile dogrulanmis 7 gun
     */
    public function yaz(string $sahip, int $sahipId, array $gunler): int
    {
        $calisma = [];
        $mola = [];
        foreach ($gunler as $g) {
            $gun = (int) $g['haftanin_gunu'];
            $ad = HaftalikSaat::GUNLER[$gun];
            $bas = HaftalikSaat::saat($g['baslangic_saati'] ?? null);
            $bit = HaftalikSaat::saat($g['bitis_saati'] ?? null);
            $mBas = HaftalikSaat::saat($g['mola_baslangic_saati'] ?? null);
            $mBit = HaftalikSaat::saat($g['mola_bitis_saati'] ?? null);
            $calisiyor = ! empty($g['calisiyor']);
            $molaVar = ! empty($g['mola_var']);

            if ($calisiyor && ! HaftalikSaat::sirali($bas, $bit)) {
                throw new GecersizIslemException("{$ad}: çalışma bitiş saati başlangıçtan sonra olmalı.");
            }
            if ($molaVar && ! HaftalikSaat::sirali($mBas, $mBit)) {
                throw new GecersizIslemException("{$ad}: mola bitiş saati başlangıçtan sonra olmalı.");
            }

            $calisma[] = ['haftanin_gunu' => $gun, 'calisiyor' => (int) $calisiyor, 'baslangic_saati' => $bas, 'bitis_saati' => $bit];
            $mola[] = ['haftanin_gunu' => $gun, 'mola_var' => (int) $molaVar, 'baslangic_saati' => $mBas, 'bitis_saati' => $mBit];
        }

        HaftalikSaatDeposu::yaz($sahip, $sahipId, HaftalikSaatDeposu::CALISMA, $calisma);
        HaftalikSaatDeposu::yaz($sahip, $sahipId, HaftalikSaatDeposu::MOLA, $mola);

        return count(array_filter($calisma, fn ($c) => $c['calisiyor']));
    }
}
