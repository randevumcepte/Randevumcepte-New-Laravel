<?php

namespace App\Services\Oturum\Hesap;

/**
 * Giris yapabilen hesap turu. Yeni tur eklemek OturumServisi'ni degistirmez; HesapListesi'ne eklenir.
 */
interface OturumHesabi
{
    /** Istekteki hesap_turu degeri ('isletme' | 'musteri'). */
    public function tur(): string;

    /** Cevaptaki user_type: v1 ile ayni ('1' isletme, '0' musteri). */
    public function kullaniciTipi(): string;

    /** Kimlik + sifre dogruysa hesap id'si. */
    public function sifreyleBul(string $kimlik, string $sifre): ?int;

    /** Kanonik telefonla kayitli hesap id'si. */
    public function telefonlaBul(string $telefon): ?int;

    /**
     * Oturum cevabinin hesaba ozel alanlari (user, musteri, ilk_sayfa). Bu istemciden girisi kapaliysa
     * GirisHatasiException firlatir.
     */
    public function acilisVerisi(int $id, ?string $bundle): array;

    public function cihazKaydet(int $id, ?string $bildirimId, ?string $cihaz, ?string $bundle): void;

    /** Sifreyi yazar, hesabin acik oturumlarini kapatir. */
    public function sifreGuncelle(int $id, string $yeniSifre): void;
}
