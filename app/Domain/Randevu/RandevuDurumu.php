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

    /** etiket() ile ayni tablo: [durum, geldi(true=1 / 0 / false=NULL / null=farketmez), etiket] */
    private const ETIKETLER = [
        [1, 1, 'Geldi'], [1, 0, 'Gelmedi'], [1, false, 'Onaylı'],
        [2, null, 'İptal'], [3, null, 'Müşteri Tarafından İptal'], [0, null, 'Beklemede'],
    ];

    /**
     * Liste "Durum" filtresi — eski randevular_liste secenekleri (geldi / gelmedi / onay
     * bekleyen / onayli / reddedilen / musteri iptali). Deger: [durum, geldi] (etiket()
     * ile ayni kodlama; null = geldi farketmez).
     */
    public const FILTRELER = [
        'geldi'         => ['Geldi', [1, 1]],
        'gelmedi'       => ['Gelmedi', [1, 0]],
        'beklemede'     => ['Onay Bekleyen', [0, null]],
        'onayli'        => ['Onaylı', [1, null]],
        'iptal'         => ['İptal / Reddedilen', [2, null]],
        'musteri_iptal' => ['Müşteri Tarafından İptal', [3, null]],
    ];

    /** @return ?array{0:int, 1:int|false|null} */
    public static function filtre(?string $kod): ?array
    {
        return self::FILTRELER[(string) $kod][1] ?? null;
    }

    /** API secenek listesi. */
    public static function filtreSecenekleri(): array
    {
        return array_map(fn ($kod, $f) => ['kod' => $kod, 'ad' => $f[0]], array_keys(self::FILTRELER), self::FILTRELER);
    }

    /**
     * Arama metninin gectigi durum etiketleri (tablo aramasi icin, Turkce kucuk harf).
     *
     * @return list<array{0:int, 1:int|false|null}>
     */
    public static function etiketeUyanlar(string $ara): array
    {
        // Turkce kucuk harf + ı/i esitlenir (IPTAL / iptal / İptal hepsi eslesir).
        $kucuk = fn (string $x) => str_replace('ı', 'i', mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $x), 'UTF-8'));
        $ara = $kucuk(trim($ara));
        if ($ara === '') {
            return [];
        }

        return array_values(array_map(fn ($e) => [$e[0], $e[1]],
            array_filter(self::ETIKETLER, fn ($e) => str_contains($kucuk($e[2]), $ara))));
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
