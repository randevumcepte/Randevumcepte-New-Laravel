<?php

namespace App\Domain\Rapor;

/**
 * Personel odemesi ve bonus/kesinti yazma kurallari (SAF) — eski ApiController primOdeApi, primHareketEkleApi,
 * primOdemeListesiApi girdi yorumu ve otomatik masraf metinleri BIREBIR.
 */
final class PrimOdemeKurali
{
    public const MASRAF_KATEGORISI = 'Personel Ödemeleri';

    private const TIP_METNI = ['maas' => 'Maaş Ödemesi', 'prim' => 'Prim Ödemesi', 'diger' => 'Avans/Bonus'];

    public static function tutar(mixed $ham): float
    {
        return (float) str_replace(',', '.', (string) $ham);
    }

    public static function donemGecerli(mixed $donem): bool
    {
        return preg_match('/^\d{4}-\d{2}$/', (string) $donem) === 1;
    }

    /** Liste donemi: bos veya gecersizse bu ay. @return array{0:string,1:string,2:string} donem, ilk gun, son gun */
    public static function listeDonemi(mixed $ham, string $buAy): array
    {
        $donem = self::donemGecerli($ham) ? (string) $ham : $buAy;

        return [$donem, $donem.'-01', date('Y-m-t', strtotime($donem.'-01'))];
    }

    public static function tarih(mixed $ham, string $bugun): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ham) ? (string) $ham : $bugun;
    }

    public static function odemeTipi(mixed $ham): string
    {
        return in_array($ham, ['maas', 'prim', 'diger'], true) ? $ham : 'diger';
    }

    /** Gunluk isaret yalniz prim odemesinde ve gecerli tarihte yazilir. */
    public static function primGun(string $odemeTipi, mixed $ham): ?string
    {
        return $odemeTipi === 'prim' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ham) ? (string) $ham : null;
    }

    public static function hareketTipi(mixed $ham): string
    {
        return $ham === 'kesinti' ? 'kesinti' : 'bonus';
    }

    public static function masrafAciklamasi(string $odemeTipi, string $donem, mixed $aciklama): string
    {
        return (self::TIP_METNI[$odemeTipi] ?? 'Personel Ödemesi').' ('.$donem.')'
            .($aciklama ? ' — '.mb_substr((string) $aciklama, 0, 80) : '');
    }

    public static function masrafNotu(?string $personelAdi, string $odemeTipi, string $donem): string
    {
        return 'Personel: '.($personelAdi ?? '').' — '.ucfirst($odemeTipi)." ($donem)";
    }

    public static function kes(mixed $metin, int $uzunluk): string
    {
        return mb_substr((string) $metin, 0, $uzunluk);
    }
}
