<?php

namespace App\Domain\Ozet;

/**
 * Ozet HIZLI EYLEM seridi — eski dashboard "rmc-quick-actions" ile birebir (yetki, etiket,
 * ikon). Link burada TUTULMAZ: her eylem bir PanelMenu ogesine (menu_slug) baglanir ve
 * linki menuyle AYNI kuraldan cozulur (PanelMenu::url) — v2 sayfasi yoksa url null.
 * Flutter `kod` ile kendi ekranina eslestirir.
 */
enum OzetEylemi: string
{
    case YeniRandevu = 'yeni_randevu';
    case YeniSatis = 'yeni_satis';
    case YeniPaket = 'yeni_paket';
    case YeniHizmet = 'yeni_hizmet';
    case IsletmeRaporlari = 'isletme_raporlari';
    case MusteriListesi = 'musteri_listesi';
    case SeansTakibi = 'seans_takibi';

    public function yetki(): string
    {
        return match ($this) {
            self::YeniRandevu      => 'randevu.olustur',
            self::YeniSatis        => 'satis.adisyon_olustur',
            self::YeniPaket        => 'paket.tanim_olustur',
            self::YeniHizmet       => 'hizmet.tanim_olustur',
            self::IsletmeRaporlari => 'rapor.satis',
            self::MusteriListesi   => 'musteri.liste_gor',
            self::SeansTakibi      => 'paket.seans_takip',
        };
    }

    public function etiket(): string
    {
        return match ($this) {
            self::YeniRandevu      => 'Yeni Randevu Oluştur',
            self::YeniSatis        => 'Yeni Satış Oluştur',
            self::YeniPaket        => 'Yeni Paket Oluştur',
            self::YeniHizmet       => 'Yeni Hizmet Oluştur',
            self::IsletmeRaporlari => 'İşletme Raporları',
            self::MusteriListesi   => 'Müşteri Listesi',
            self::SeansTakibi      => 'Seans Takibi',
        };
    }

    public function ikon(): string
    {
        return match ($this) {
            self::YeniRandevu      => 'bi-calendar-plus-fill',
            self::YeniSatis        => 'bi-receipt',
            self::YeniPaket        => 'bi-gift-fill',
            self::YeniHizmet       => 'bi-clipboard2-check',
            self::IsletmeRaporlari => 'bi-bar-chart-line-fill',
            self::MusteriListesi   => 'bi-people-fill',
            self::SeansTakibi      => 'bi-list-check',
        };
    }

    /** Linkin cozulecegi PanelMenu slug'i + eke sorgu (ornegin Ayarlar → Hizmetler sekmesi). */
    public function menuSlug(): string
    {
        return match ($this) {
            self::YeniRandevu      => 'takvim-v2',
            self::YeniSatis        => 'satis-takibi',
            self::YeniPaket        => 'paketsatislari',
            self::YeniHizmet       => 'ayarlar',
            self::IsletmeRaporlari => 'isletmeraporlari',
            self::MusteriListesi   => 'musteriler',
            self::SeansTakibi      => 'seanstakip',
        };
    }

    /** @return array<string,string> */
    public function ekSorgu(): array
    {
        return $this === self::YeniHizmet ? ['p' => 'hizmetler'] : [];
    }
}
