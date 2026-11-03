<?php

namespace App\Domain\Seans;

/**
 * Seansli satis kalemi turu. Seanslar (adisyon_paket_seanslar) ya bir paket satirina
 * (adisyon_paketler) ya da seansli tek hizmet satirina (adisyon_hizmetler) baglanir;
 * tablo/kolon farki burada TEK yerde — servislerde if/else tekrari yok.
 */
enum SeansKalemTuru: string
{
    case PAKET = 'paket';
    case HIZMET = 'hizmet';

    /** Satis kalemi tablosu. */
    public function tablo(): string
    {
        return $this === self::PAKET ? 'adisyon_paketler' : 'adisyon_hizmetler';
    }

    /** adisyon_paket_seanslar'daki bag kolonu. */
    public function seansKolonu(): string
    {
        return $this === self::PAKET ? 'adisyon_paket_id' : 'adisyon_hizmet_id';
    }

    /** Seans satirindan tur (paket bagi varsa paket). */
    public static function seanstan(object $seans): ?self
    {
        if (! empty($seans->adisyon_paket_id)) {
            return self::PAKET;
        }

        return ! empty($seans->adisyon_hizmet_id) ? self::HIZMET : null;
    }

    /** Seans satirindaki kalem id'si. */
    public function seansKalemId(object $seans): int
    {
        return (int) $seans->{$this->seansKolonu()};
    }
}
