<?php

namespace App\Domain\Rapor;

use App\Domain\Satis\KalemTuru;

/**
 * Personel prim hesabi (saf) — eski Prim & Hak Edis (StoreAdminController::primHakedisVerisi)
 * formulu BIREBIR; v2 Satis Raporlari > Personel sekmesi ve ileride hak edis ayni sinifi
 * kullanir (eski kodda uc farkli prim formulu vardi: web raporlar, mobil API, hak edis).
 *
 * Kural:
 *   gelir  = personelin kalemlerine yapilan TAHSILAT (kalem tahsilat tablolari)
 *   prim   = {tur}_prim_detayli ise kalem (katalog) bazli oran (personel_prim_oranlari),
 *            degilse duz {tur}_prim_yuzde
 *   SEANSLI hizmet (randevusuz, seans_sayisi>0) gelir + prim PAKET kovasina, DUZ
 *   paket_prim_yuzde ile (detayli paket orani katalog paket_id'ye bagli oldugundan uygulanmaz).
 * Yuvarlama eskisiyle ayni: detaylida grup primleri toplanip, duzde tutar*yuzde 2 haneye.
 */
final class PrimHesap
{
    /**
     * @param  iterable<array{personel_id:int, tur:string, kalem_id:int, seansli:bool, odenen:float}>  $gruplar
     *         personel + tur + katalog kalemi basina tahsilat toplamlari
     * @param  array<int, array<string,mixed>>  $personeller  id => {hizmet_prim_yuzde, hizmet_prim_detayli, urun_..., paket_...}
     * @param  array<int, array<string, array<int,float>>>  $oranlar  [personel_id][tur][kalem_id] => yuzde
     * @return array<int, array<string,float>>  personel_id => {hizmet_geliri, hizmet_primi, urun_geliri, urun_primi, paket_geliri, paket_primi, toplam_gelir, toplam_prim}
     */
    public static function hesapla(iterable $gruplar, array $personeller, array $oranlar): array
    {
        // [pid][kova] => ['gelir' => x, 'detayli' => [kalem_id => odenen], 'duz' => toplam]
        $birikim = [];
        foreach ($gruplar as $g) {
            $pid = (int) $g['personel_id'];
            if (! isset($personeller[$pid])) {
                continue; // pasif/arsivli/silinmis personel: eskisiyle ayni, sayilmaz
            }
            $tur = KalemTuru::from($g['tur']);
            $odenen = (float) $g['odenen'];
            $seansli = $tur === KalemTuru::Hizmet && ! empty($g['seansli']);
            $kova = $seansli ? KalemTuru::Paket->value : $tur->value;

            $b = &$birikim[$pid][$kova];
            $b['gelir'] = ($b['gelir'] ?? 0) + $odenen;
            if (! $seansli && ! empty($personeller[$pid][$tur->value.'_prim_detayli'])) {
                $b['detayli'][(int) $g['kalem_id']] = ($b['detayli'][(int) $g['kalem_id']] ?? 0) + $odenen;
            } else {
                $b['duz'] = ($b['duz'] ?? 0) + $odenen;
            }
            unset($b);
        }

        $sonuc = [];
        foreach ($birikim as $pid => $kovalar) {
            $p = $personeller[$pid];
            $satir = [];
            foreach (KalemTuru::cases() as $tur) {
                $b = $kovalar[$tur->value] ?? [];
                $prim = 0.0;
                if (! empty($b['detayli'])) {
                    $detay = 0.0;
                    foreach ($b['detayli'] as $kalemId => $tutar) {
                        $detay += $tutar * (float) ($oranlar[$pid][$tur->value][$kalemId] ?? 0) / 100;
                    }
                    $prim += round($detay, 2);
                }
                if (! empty($b['duz'])) {
                    $prim += round($b['duz'] * (float) ($p[$tur->value.'_prim_yuzde'] ?? 0) / 100, 2);
                }
                $satir[$tur->value.'_geliri'] = round((float) ($b['gelir'] ?? 0), 2);
                $satir[$tur->value.'_primi'] = round($prim, 2);
            }
            $satir['toplam_gelir'] = round($satir['hizmet_geliri'] + $satir['urun_geliri'] + $satir['paket_geliri'], 2);
            $satir['toplam_prim'] = round($satir['hizmet_primi'] + $satir['urun_primi'] + $satir['paket_primi'], 2);
            $sonuc[$pid] = $satir;
        }

        return $sonuc;
    }
}
