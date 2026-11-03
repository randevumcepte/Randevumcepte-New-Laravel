<?php

namespace App\Domain\Cark;

/**
 * Çark / puan ödülü KUPONUNUN durumu — saf hesap. Eski web liste filtresi, doğrulama kutusu
 * ve mobil uç aynı üç durumu ayrı ayrı türetiyordu; kural burada TEK yerde. Liste filtresi
 * (SQL) CarkKuponServisi'nde bu sabitlerle kurulur.
 *
 *   kullanildi : kullanildi = 1
 *   sure_doldu : kullanılmamış + gecerlilik_tarihi < bugün
 *   gecerli    : kullanılmamış + (süresiz | gecerlilik_tarihi >= bugün)
 */
final class KuponDurumu
{
    public const TUMU = 'tumu';
    public const GECERLI = 'gecerli';
    public const KULLANILDI = 'kullanildi';
    public const SURE_DOLDU = 'sure_doldu';

    /** kod => [etiket, rozet rengi] — filtre sekmeleri bu sırayla çizilir. */
    private const TANIM = [
        self::TUMU       => ['Tümü', 'gri'],
        self::GECERLI    => ['Geçerli', 'yesil'],
        self::KULLANILDI => ['Kullanıldı', 'mavi'],
        self::SURE_DOLDU => ['Süresi Doldu', 'kirmizi'],
    ];

    /** @param  string  $bugun  Y-m-d (saat dışarıdan → test edilebilir) */
    public static function hesapla(bool $kullanildi, ?string $gecerlilikTarihi, string $bugun): string
    {
        if ($kullanildi) {
            return self::KULLANILDI;
        }

        return $gecerlilikTarihi && substr($gecerlilikTarihi, 0, 10) < $bugun ? self::SURE_DOLDU : self::GECERLI;
    }

    public static function filtre(?string $durum): string
    {
        return isset(self::TANIM[(string) $durum]) ? (string) $durum : self::TUMU;
    }

    public static function etiket(string $durum): string
    {
        return self::TANIM[$durum][0] ?? $durum;
    }

    /** Filtre sekmeleri: [{kod, ad, renk}] */
    public static function sozluk(): array
    {
        return array_map(fn ($kod) => ['kod' => $kod, 'ad' => self::TANIM[$kod][0], 'renk' => self::TANIM[$kod][1]], array_keys(self::TANIM));
    }
}
