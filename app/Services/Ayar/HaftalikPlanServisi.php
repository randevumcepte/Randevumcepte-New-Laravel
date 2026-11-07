<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\HaftalikPlan;
use App\Domain\Ayar\HaftalikSaat;
use App\Support\HaftalikSaatDeposu;

/**
 * 7 gunluk calisma + mola PLANI — sahipten bagimsiz (salon, cihaz; personel ayni depoyu kullanir).
 *
 * Plan bicimi her yerde ayni (web + Flutter): {haftanin_gunu, gun_adi, calisiyor, baslangic_saati,
 * bitis_saati, mola_var, mola_baslangic_saati, mola_bitis_saati}. Kaydi olmayan gun varsayilanla
 * doner. Yazma kurali HaftalikPlan'da.
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
     * Dogrula + sil-ve-yaz (cagiran transaction acar). Donus: yazilan yarinin acik gun sayisi
     * (iki yari yazilirsa calisilan gun). $yalniz ve $geceDevri icin bkz. HaftalikPlan.
     *
     * @param  list<array>  $gunler  HaftalikPlanKurallari ile dogrulanmis 7 gun
     */
    public function yaz(string $sahip, int $sahipId, array $gunler, ?string $yalniz = null, bool $geceDevri = false): int
    {
        ['calisma' => $calisma, 'mola' => $mola] = HaftalikPlan::satirlar($gunler, $yalniz, $geceDevri);

        if ($calisma !== null) {
            HaftalikSaatDeposu::yaz($sahip, $sahipId, HaftalikSaatDeposu::CALISMA, $calisma);
        }
        if ($mola !== null) {
            HaftalikSaatDeposu::yaz($sahip, $sahipId, HaftalikSaatDeposu::MOLA, $mola);
        }

        return $calisma !== null
            ? count(array_filter($calisma, fn ($c) => $c['calisiyor']))
            : count(array_filter($mola ?? [], fn ($c) => $c['mola_var']));
    }
}
