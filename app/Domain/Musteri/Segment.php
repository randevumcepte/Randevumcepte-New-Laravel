<?php

namespace App\Domain\Musteri;

/**
 * Musteri segmenti (liste sekmeleri + detay basligi + aktif/sadik indirimi) — TEK kural.
 *
 * Eski sistemde uc farkli tanim vardi (liste >=3, detay basligi >3, UI metni "3 ay
 * icinde"); v2'de LISTE kurali kanoniktir (karar 3 Kas 2026): salondaki tahsilat
 * SATIRI sayisi, zaman penceresi yok.
 *   0 tahsilat   → Pasif
 *   1-2 tahsilat → Aktif
 *   3+ tahsilat  → Sadik
 *
 * Filtre kodlari eski /musterilistegetir/{durum} ve Flutter sekmeleriyle ayni:
 *   0 pasif · 1 aktif · 2 sadik · 3 tumu · 4 yeni eklenen.
 */
final class Segment
{
    public const PASIF = 0;

    public const AKTIF = 1;

    public const SADIK = 2;

    public const TUMU = 3;

    public const YENI = 4;

    /** Sadik esigi (dahil). */
    public const SADIK_ESIK = 3;

    /**
     * "Yeni eklenen" sekmesi: bu tarihten sonra portfoye eklenenler (toplu importlu eski
     * kayitlar haric) — eski StoreAdminController::YENI_MUSTERI_BASLANGIC ile ayni.
     */
    public const YENI_BASLANGIC = '2026-06-11 00:00:00';

    private const ETIKETLER = [
        self::PASIF => 'Pasif',
        self::AKTIF => 'Aktif',
        self::SADIK => 'Sadık',
    ];

    public static function tahsilatSayisindan(int $sayi): int
    {
        return match (true) {
            $sayi <= 0              => self::PASIF,
            $sayi < self::SADIK_ESIK => self::AKTIF,
            default                 => self::SADIK,
        };
    }

    /** @return array{kod:int, etiket:string} */
    public static function ozet(int $tahsilatSayisi): array
    {
        $kod = self::tahsilatSayisindan($tahsilatSayisi);

        return ['kod' => $kod, 'etiket' => self::ETIKETLER[$kod]];
    }

    /** Gecerli liste filtresi mi? Gecersizse TUMU. */
    public static function filtre(mixed $durum): int
    {
        $d = is_numeric($durum) ? (int) $durum : self::TUMU;

        return in_array($d, [self::PASIF, self::AKTIF, self::SADIK, self::TUMU, self::YENI], true) ? $d : self::TUMU;
    }

    /**
     * Tahsilat sayisi SQL ifadesine segment kosulu (PASIF/AKTIF/SADIK icin; digerlerinde null).
     * Liste ve sayaclar ayni araliklari kullanir — esik tek yerde.
     */
    public static function sqlKosulu(int $durum, string $sayiIfadesi): ?string
    {
        return match ($durum) {
            self::PASIF => "$sayiIfadesi = 0",
            self::AKTIF => "$sayiIfadesi BETWEEN 1 AND ".(self::SADIK_ESIK - 1),
            self::SADIK => "$sayiIfadesi >= ".self::SADIK_ESIK,
            default     => null,
        };
    }
}
