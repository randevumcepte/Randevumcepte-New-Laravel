<?php

namespace App\Domain\Satis;

/**
 * Satis kalemi turleri — kalem tablosu, kalem tahsilat tablosu, katalog tablosu ve
 * kolon adlari TEK yerde (raporlar ve kalem islemleri ayni eslemeyi kullanir).
 *
 * SEANSLI HIZMET: randevusuz (randevu_id NULL) VE seans_sayisi > 0 olan hizmet kalemi
 * paket satisidir — eski Prim/Hak Edis (primHakedisVerisi) ve mobil rapor kurali.
 * Raporlarda bu kalemler Paket kovasina gider (bkz. seansliSql()).
 */
enum KalemTuru: string
{
    case Hizmet = 'hizmet';
    case Urun = 'urun';
    case Paket = 'paket';

    public function kalemTablosu(): string
    {
        return match ($this) {
            self::Hizmet => 'adisyon_hizmetler',
            self::Urun   => 'adisyon_urunler',
            self::Paket  => 'adisyon_paketler',
        };
    }

    public function tahsilatTablosu(): string
    {
        return match ($this) {
            self::Hizmet => 'tahsilat_hizmetler',
            self::Urun   => 'tahsilat_urunler',
            self::Paket  => 'tahsilat_paketler',
        };
    }

    /** Kalem tahsilat tablosundaki kalem FK'si (adisyon_hizmet_id ...). */
    public function tahsilatFk(): string
    {
        return 'adisyon_'.$this->value.'_id';
    }

    /** Kalem tablosundaki katalog FK'si (hizmet_id / urun_id / paket_id). */
    public function katalogFk(): string
    {
        return $this->value.'_id';
    }

    public function katalogTablosu(): string
    {
        return match ($this) {
            self::Hizmet => 'hizmetler',
            self::Urun   => 'urunler',
            self::Paket  => 'paketler',
        };
    }

    public function adKolonu(): string
    {
        return $this->value.'_adi';
    }

    public function etiket(): string
    {
        return match ($this) {
            self::Hizmet => 'Hizmet',
            self::Urun   => 'Ürün',
            self::Paket  => 'Paket',
        };
    }

    /** Hizmet kaleminin seansli (paket) olup olmadigi — SQL ifadesi ($a: kalem alias'i). */
    public static function seansliSql(string $a = 'k'): string
    {
        return "({$a}.randevu_id IS NULL AND COALESCE({$a}.seans_sayisi,0) > 0)";
    }
}
