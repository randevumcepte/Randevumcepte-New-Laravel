<?php

namespace App\Domain\Randevu;

/**
 * Randevu durum etiketi (liste ekranlari) — eski randevu_liste_getir CASE'i ile ayni:
 *   durum=1 + geldi=1 → Geldi · durum=1 + geldi=0 → Gelmedi · durum=1 + geldi NULL → Onaylı
 *   durum=2 → İptal · durum=3 → Müşteri Tarafından İptal · durum=0 → Beklemede
 * renk = v2-bilesen.css .rc-rozet-<renk>.
 */
final class RandevuDurumu
{
    /** @return array{kod:string, etiket:string, renk:string} */
    public static function etiket(mixed $durum, mixed $geldi): array
    {
        $durum = (int) $durum;
        $geldi = $geldi === null || $geldi === '' ? null : (int) $geldi;

        [$kod, $etiket, $renk] = match (true) {
            $durum === 1 && $geldi === 1 => ['geldi', 'Geldi', 'yesil'],
            $durum === 1 && $geldi === 0 => ['gelmedi', 'Gelmedi', 'kirmizi'],
            $durum === 1                 => ['onayli', 'Onaylı', 'mavi'],
            $durum === 2                 => ['iptal', 'İptal', 'gri'],
            $durum === 3                 => ['musteri_iptal', 'Müşteri Tarafından İptal', 'gri'],
            default                      => ['beklemede', 'Beklemede', 'turuncu'],
        };

        return ['kod' => $kod, 'etiket' => $etiket, 'renk' => $renk];
    }

    /**
     * Liste satirinda izinli islemler — eski randevu_liste_getir islem menusu ile ayni:
     *   Beklemede     → Duzenle, Onayla (randevu.onayla), Iptal Et
     *   Onayli/Gelmedi→ Duzenle, Iptal Et
     *   Geldi         → (durum islemi yok; telafi/not detay cekmecesinde)
     *   Iptal (2/3)   → Duzenle
     * Duzenle/Iptal randevu.duzenle_iptal ister.
     *
     * @return array{onayla:bool, duzenle:bool, iptal:bool}
     */
    public static function aksiyonlar(mixed $durum, mixed $geldi, bool $onaylaYetki, bool $duzenleYetki): array
    {
        $durum = (int) $durum;
        $geldiMi = $durum === 1 && $geldi !== null && $geldi !== '' && (int) $geldi === 1;

        return [
            'onayla'  => $onaylaYetki && $durum === 0,
            'duzenle' => $duzenleYetki && ! $geldiMi,
            'iptal'   => $duzenleYetki && ($durum === 0 || ($durum === 1 && ! $geldiMi)),
        ];
    }
}
