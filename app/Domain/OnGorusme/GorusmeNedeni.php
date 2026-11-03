<?php

namespace App\Domain\OnGorusme;

use InvalidArgumentException;

/**
 * Ön görüşme NEDENİ — görüşmenin konusu olan TEK katalog kalemi (paket | ürün | hizmet).
 * DB'de üç ayrı kolonda tutulur (on_gorusmeler.paket_id / urun_id / hizmet_id; yalnız biri dolu).
 *
 * Üç istemci biçimi TEK değere çözülür (kod tekrarı yok; eski iki kanal ayrı ayrı ayrıştırıyordu):
 *   - v2      : {neden_tur: 'paket'|'urun'|'hizmet', neden_id: 12}
 *   - mobil v1: {paket_id | urun_id | hizmet_id} (biri dolu, "null" metni boş sayılır)
 *   - eski web: {paket_urun: "12" | "urun-5" | "hizmet-3"}
 * Satırdan okurken öncelik paket > ürün > hizmet (eski liste CASE'i ile aynı).
 */
final class GorusmeNedeni
{
    public const PAKET = 'paket';
    public const URUN = 'urun';
    public const HIZMET = 'hizmet';

    /** tur => [DB kolonu, etiket] */
    private const TURLER = [
        self::PAKET  => ['paket_id', 'Paket'],
        self::URUN   => ['urun_id', 'Ürün'],
        self::HIZMET => ['hizmet_id', 'Hizmet'],
    ];

    private function __construct(public readonly string $tur, public readonly int $id) {}

    public static function olustur(string $tur, int $id): self
    {
        if (! isset(self::TURLER[$tur])) {
            throw new InvalidArgumentException('Ön görüşme nedeni türü geçersiz.');
        }
        if ($id <= 0) {
            throw new InvalidArgumentException('Ön görüşme nedeni seçilmedi.');
        }

        return new self($tur, $id);
    }

    /** @return string[] geçerli tür kodları (doğrulama kuralı için) */
    public static function turler(): array
    {
        return array_keys(self::TURLER);
    }

    /** İstek gövdesinden (yukarıdaki üç biçim); hiçbiri yoksa null. */
    public static function istekten(array $g): ?self
    {
        if (self::dolu($g['neden_tur'] ?? null)) {
            return self::olustur((string) $g['neden_tur'], (int) ($g['neden_id'] ?? 0));
        }
        if (self::dolu($g['paket_urun'] ?? null)) {
            return self::eskiDegerden((string) $g['paket_urun']);
        }

        return self::kolonlardan($g);
    }

    /** Eski web select değeri: "12" (paket), "urun-5", "hizmet-3". */
    public static function eskiDegerden(string $deger): ?self
    {
        if (preg_match('/^(urun|hizmet)-(\d+)$/', trim($deger), $m)) {
            return self::olustur($m[1], (int) $m[2]);
        }

        return ctype_digit(trim($deger)) ? self::olustur(self::PAKET, (int) $deger) : null;
    }

    /** paket_id / urun_id / hizmet_id alanlarından (DB satırı ya da mobil v1 isteği). */
    public static function kolonlardan(array|object $satir): ?self
    {
        $satir = (array) $satir;
        foreach (self::TURLER as $tur => [$kolon]) {
            if (self::dolu($satir[$kolon] ?? null) && (int) $satir[$kolon] > 0) {
                return new self($tur, (int) $satir[$kolon]);
            }
        }

        return null;
    }

    /** DB'ye yazılacak üç kolon (seçili olan dolu, diğerleri NULL). */
    public function kolonlar(): array
    {
        $k = [];
        foreach (self::TURLER as $tur => [$kolon]) {
            $k[$kolon] = $tur === $this->tur ? $this->id : null;
        }

        return $k;
    }

    public function etiket(): string
    {
        return self::TURLER[$this->tur][1];
    }

    /** Seçim anahtarı ("paket-12") — istemcilerin tek select'te üç türü taşıması için. */
    public function anahtar(): string
    {
        return $this->tur.'-'.$this->id;
    }

    private static function dolu(mixed $v): bool
    {
        return $v !== null && $v !== '' && $v !== 'null' && $v !== 0 && $v !== '0';
    }
}
