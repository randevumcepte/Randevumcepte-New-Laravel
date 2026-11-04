<?php

namespace App\Domain\Ders;

/**
 * Grup dersi raporu — SAF toplama (DB yok). Eski DersRaporServisi::ozet ile ayni tanimlar:
 *
 *   katilim  = iptal ve bekleme DISI katilimci (rezerve + geldi + gelmedi + telafi)
 *   doluluk% = katilim / toplam kapasite
 *   noshow%  = gelmedi / (geldi + gelmedi)
 *
 * Girdi: oturumlar [{id, kapasite, personel_id, ders_tipi}], katilimci durumlari
 * [oturum_id => [durum, ...]] (iptal dahil olabilir), personel adlari [id => ad].
 */
final class DersRaporu
{
    private const VARSAYILAN_DERS = 'Grup Dersi';

    public static function hesapla(iterable $oturumlar, array $durumlar, array $personelAdlari): array
    {
        $ozet = ['oturum' => 0, 'kapasite' => 0, 'katilim' => 0, 'gelen' => 0, 'gelmedi' => 0, 'bekleme' => 0];
        $egitmen = [];
        $ders = [];

        foreach ($oturumlar as $o) {
            $sayim = array_count_values($durumlar[$o->id] ?? []);
            $gelen = $sayim[KatilimciDurumu::GELDI->value] ?? 0;
            $gelmedi = $sayim[KatilimciDurumu::GELMEDI->value] ?? 0;
            $bekleme = $sayim[KatilimciDurumu::BEKLEME->value] ?? 0;
            $katilim = array_sum($sayim) - $bekleme - ($sayim[KatilimciDurumu::IPTAL->value] ?? 0);
            $kapasite = (int) $o->kapasite;
            $artis = ['oturum' => 1, 'kapasite' => $kapasite, 'katilim' => $katilim, 'gelen' => $gelen];

            self::topla($ozet, $artis + ['gelmedi' => $gelmedi, 'bekleme' => $bekleme]);

            $pid = (int) ($o->personel_id ?: 0);
            $egitmen[$pid] ??= ['personel' => $personelAdlari[$pid] ?? '—', 'oturum' => 0, 'kapasite' => 0, 'katilim' => 0, 'gelen' => 0, 'gelmedi' => 0];
            self::topla($egitmen[$pid], $artis + ['gelmedi' => $gelmedi]);

            $tip = $o->ders_tipi ?: self::VARSAYILAN_DERS;
            $ders[$tip] ??= ['ders_tipi' => $tip, 'oturum' => 0, 'kapasite' => 0, 'katilim' => 0, 'gelen' => 0];
            self::topla($ders[$tip], $artis);
        }

        $ozet['doluluk'] = self::yuzde($ozet['katilim'], $ozet['kapasite']);
        $ozet['noshow'] = self::yuzde($ozet['gelmedi'], $ozet['gelen'] + $ozet['gelmedi']);

        return ['ozet' => $ozet, 'egitmen' => self::listele($egitmen), 'ders' => self::listele($ders)];
    }

    private static function topla(array &$hedef, array $artis): void
    {
        foreach ($artis as $k => $v) {
            $hedef[$k] += $v;
        }
    }

    /** Doluluk% ekler, katilima gore azalan siralar. */
    private static function listele(array $satirlar): array
    {
        $liste = array_map(fn ($s) => $s + ['doluluk' => self::yuzde($s['katilim'], $s['kapasite'])], array_values($satirlar));
        usort($liste, fn ($a, $b) => $b['katilim'] <=> $a['katilim']);

        return $liste;
    }

    private static function yuzde(int $pay, int $payda): int
    {
        return $payda > 0 ? (int) round($pay / $payda * 100) : 0;
    }
}
