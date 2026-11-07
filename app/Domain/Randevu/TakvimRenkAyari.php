<?php

namespace App\Domain\Randevu;

/**
 * Salonun takvim renk ayarlari (tek renk, renk sirasi, yazi rengi). SAF.
 * Mobil renk bicimi 0xFFRRGGBB'dir. Tek renk aciksa renk sirasi yok sayilir.
 */
final class TakvimRenkAyari
{
    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    /** @param  array<int,array{bg:string,text:?string}>  $sira */
    private function __construct(
        public readonly ?string $tekRenk,
        public readonly array $sira,
        public readonly ?string $yaziRengi,
    ) {}

    public static function salondan(?object $salon): self
    {
        $tekRenk = ($salon->takvim_tek_renk_aktif ?? null) && ! empty($salon->takvim_tek_renk)
            ? '0xFF'.strtoupper(ltrim((string) $salon->takvim_tek_renk, '#'))
            : null;

        $yazi = ($salon->takvim_yazi_rengi_aktif ?? null) ? self::mobilRenk($salon->takvim_yazi_rengi ?? null) : null;

        return new self($tekRenk, $tekRenk === null ? self::siraCoz($salon->takvim_renk_sirasi ?? null) : [], $yazi);
    }

    public static function mobilRenk(mixed $hex): ?string
    {
        return is_string($hex) && preg_match(self::HEX, $hex) ? '0xFF'.strtoupper(ltrim($hex, '#')) : null;
    }

    /** @return array<int,array{bg:string,text:?string}> */
    private static function siraCoz(mixed $ham): array
    {
        $dizi = is_string($ham) ? json_decode($ham, true) : $ham;
        if (! is_array($dizi)) {
            return [];
        }

        $sira = [];
        foreach ($dizi as $oge) {
            $bg = self::mobilRenk(is_array($oge) ? ($oge['bg'] ?? null) : $oge);
            if ($bg !== null) {
                $sira[] = ['bg' => $bg, 'text' => is_array($oge) ? self::mobilRenk($oge['text'] ?? null) : null];
            }
        }

        return $sira;
    }
}
