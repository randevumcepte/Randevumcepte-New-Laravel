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
}
