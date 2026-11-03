<?php

namespace App\Domain\Stok;

/**
 * Stok hareket tipleri — stok_hareketleri.hareket_tipi degerleri (eski sistemle AYNI
 * string'ler). Isaret kurali TEK yerde: cikis tipleri daima negatif, giris tipleri
 * daima pozitif; acilis/sayim/manuel duzeltmeler gelen isaretiyle yazilir.
 * Etiket + renk sunucudan gelir (web ve Flutter ayni rozeti cizer).
 */
enum HareketTipi: string
{
    case Acilis = 'acilis';
    case Alis = 'alis';
    case Satis = 'satis';
    case Sarf = 'sarf';
    case Fire = 'fire';
    case Sayim = 'sayim';
    case Iade = 'iade';
    case TransferGiris = 'transfer_giris';
    case TransferCikis = 'transfer_cikis';
    case Manuel = 'manuel';

    public function etiket(): string
    {
        return match ($this) {
            self::Acilis        => 'Açılış',
            self::Alis          => 'Alış',
            self::Satis         => 'Satış',
            self::Sarf          => 'Sarf',
            self::Fire          => 'Fire',
            self::Sayim         => 'Sayım',
            self::Iade          => 'İade',
            self::TransferGiris => 'Transfer Giriş',
            self::TransferCikis => 'Transfer Çıkış',
            self::Manuel        => 'Manuel',
        };
    }

    public function renk(): string
    {
        return match ($this) {
            self::Acilis        => '#6A1B9A',
            self::Alis, self::Iade => '#43A047',
            self::Satis         => '#1565C0',
            self::Sarf          => '#AD1457',
            self::Fire          => '#E53935',
            self::Sayim         => '#F6A609',
            self::TransferGiris => '#0288D1',
            self::TransferCikis => '#FF7043',
            self::Manuel        => '#455A64',
        };
    }

    /** Miktari tipin yonune gore isaretler (kullanici "5" girer; fire → -5). */
    public function isaretle(float $miktar): float
    {
        return match ($this) {
            self::Satis, self::Sarf, self::Fire, self::TransferCikis => -abs($miktar),
            self::Alis, self::Iade, self::TransferGiris              => abs($miktar),
            default                                                   => $miktar,
        };
    }

    /** Kullanicinin "manuel hareket" formundan secebilecegi tipler (eski mobil listesi + sarf). */
    public static function manuelSecilebilir(): array
    {
        return [self::Fire, self::Manuel, self::Iade, self::Sarf];
    }

    /** @return string[] manuel hareket dogrulama listesi */
    public static function manuelDegerler(): array
    {
        return array_map(fn (self $t) => $t->value, self::manuelSecilebilir());
    }

    /** UI secenek listesi: [{deger, etiket, renk}] */
    public static function secenekler(array $tipler): array
    {
        return array_map(fn (self $t) => ['deger' => $t->value, 'etiket' => $t->etiket(), 'renk' => $t->renk()], $tipler);
    }

    /** Bilinmeyen eski kayit tipleri icin guvenli etiket/renk. */
    public static function sunum(?string $deger): array
    {
        $t = self::tryFrom((string) $deger);

        return ['etiket' => $t?->etiket() ?? (string) $deger, 'renk' => $t?->renk() ?? '#455A64'];
    }
}
