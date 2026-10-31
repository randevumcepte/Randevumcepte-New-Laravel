<?php

namespace App\Domain\Randevu;

/**
 * Randevu durum degisikliklerinde APS (adisyon_paket_seanslar) satirlarina ne
 * olacagini hesaplayan SAF kurallar (DB yok, unit-test edilir).
 *
 * Girdi: randevunun seanslari [['id' => int, 'geldi' => null|0|1|2], ...]
 * Cikti: ['guncelle' => [id => [kolon => deger]], 'sil' => [id, ...]]
 *
 * TELAFI KORUMASI: geldi=2 (telafi slotu = bekleyen ucretsiz seans hakki)
 * gelmedi / isaret-kaldir / iptal yollarinda ASLA ezilmez veya silinmez.
 * Tek istisna "geldi": telafi slotu kullanilmis sayilir (eski davranis).
 *
 * Eski monolitten BILINCLI farklar (v2 duzeltmesi):
 *   - isaretKaldir: eski kod telafi slotunu da null'luyordu (hak kayboluyordu).
 *   - iptal: eski kod telafi slotunu da siliyordu (hak kayboluyordu).
 */
final class SeansDurumGecisi
{
    public const TELAFI = 2;

    public static function telafiMi(array $seans): bool
    {
        return $seans['geldi'] !== null && (int) $seans['geldi'] === self::TELAFI;
    }

    /**
     * GELDI. Secim yoksa (eski istemci) tum seanslar geldi=1 (telafi dahil).
     * Secim varsa: secilenler geldi=1 + dusulen_miktar (min 1, varsayilan 1);
     * secilmeyen TELAFI-DISI seanslar silinir.
     *
     * @param  array<int,int>|null  $secilenIdler
     * @param  array<int|string,int|string>  $miktarlar  [seansId => miktar]
     */
    public static function geldi(array $seanslar, ?array $secilenIdler, array $miktarlar = []): array
    {
        $guncelle = [];
        $sil = [];

        if ($secilenIdler === null) {
            foreach ($seanslar as $s) {
                $guncelle[(int) $s['id']] = ['geldi' => 1];
            }

            return ['guncelle' => $guncelle, 'sil' => $sil];
        }

        $secilen = array_flip(array_map('intval', $secilenIdler));
        foreach ($seanslar as $s) {
            $id = (int) $s['id'];
            if (isset($secilen[$id])) {
                $m = $miktarlar[$id] ?? $miktarlar[(string) $id] ?? 1;
                $guncelle[$id] = ['geldi' => 1, 'dusulen_miktar' => max(1, (int) $m)];
            } elseif (! self::telafiMi($s)) {
                $sil[] = $id;
            }
        }

        return ['guncelle' => $guncelle, 'sil' => $sil];
    }

    /** GELMEDI (seans dusumu onaylandiysa): telafi-disi seanslar geldi=0. */
    public static function gelmedi(array $seanslar): array
    {
        return self::telafiDisinaUygula($seanslar, ['geldi' => 0]);
    }

    /** ISARET KALDIR: telafi-disi seanslar geldi=null (telafi korunur). */
    public static function isaretKaldir(array $seanslar): array
    {
        return self::telafiDisinaUygula($seanslar, ['geldi' => null]);
    }

    /**
     * IPTAL: telafi-disi seanslar silinir → paket bakiyesine geri doner.
     * Telafi slotu korunur (bekleyen hak bir sonraki randevuya baglanabilir).
     */
    public static function iptal(array $seanslar): array
    {
        $sil = [];
        foreach ($seanslar as $s) {
            if (! self::telafiMi($s)) {
                $sil[] = (int) $s['id'];
            }
        }

        return ['guncelle' => [], 'sil' => $sil];
    }

    private static function telafiDisinaUygula(array $seanslar, array $degerler): array
    {
        $guncelle = [];
        foreach ($seanslar as $s) {
            if (! self::telafiMi($s)) {
                $guncelle[(int) $s['id']] = $degerler;
            }
        }

        return ['guncelle' => $guncelle, 'sil' => []];
    }
}
