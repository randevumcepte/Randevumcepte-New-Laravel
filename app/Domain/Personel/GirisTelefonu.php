<?php

namespace App\Domain\Personel;

/**
 * Giris telefonu (isletmeyetkilileri.gsm1) — TEK saf kural (cerceve/DB bagimsiz). gsm1 giris kimligidir; bir
 * numara tek hesaba aittir ve bir isletme baska isletmenin hesabini ele geciremez. Profil (kendi numarasini
 * degistirme) ve personel ekle/duzenle (web ve mobil ayni servis) GirisTelefonuDenetimi uzerinden buraya gelir;
 * olgulari (numara kimde, hesap hangi salonlarda) servis DB'den cozer. Engel mesaji doner (servis 422), yoksa null.
 *
 *  - NUMARA   : bir hesabin gsm1'i, baska bir hesapta kayitli numaraya cevrilemez (cift giris kimligi olmasin).
 *  - BAGLAMA  : personel satiri telefonla bulunan MEVCUT bir hesaba yalniz o hesap bu salondan baska hicbir salonda
 *               personel / rol satirina sahip degilse baglanir (ayni salonun eski/arsivli personelini ya da
 *               salonsuz hesabi yeniden baglamak mesru; baska isletmenin sahibini/personelini baglamak degil).
 *  - DEGISIM  : baska salonlarda da kayitli bir hesabin giris telefonunu bu salondan yalniz hesabin kendisi
 *               degistirebilir (aksi halde sifre sifirlamayla hesap devralinir).
 */
final class GirisTelefonu
{
    public const BASKA_HESAPTA = 'Bu telefon numarası başka bir hesapta kayıtlı.';

    public const BASKA_ISLETMEDE = 'Bu telefon numarası başka bir işletmede kayıtlı bir hesaba ait; bu personele bağlanamaz.';

    public const DEGISIM_YALNIZ_KENDISI = 'Bu personelin giriş hesabı başka bir işletmede de kayıtlı; giriş telefonunu yalnız kendisi değiştirebilir.';

    /**
     * @param  int|null  $hesapId         gsm1'i degisecek hesap (null: yeni hesap)
     * @param  int|null  $numaraSahibiId  yeni numaranin kayitli oldugu hesap (null: numara serbest)
     */
    public static function numaraEngeli(?int $hesapId, ?int $numaraSahibiId): ?string
    {
        return $numaraSahibiId !== null && $numaraSahibiId !== $hesapId ? self::BASKA_HESAPTA : null;
    }

    /**
     * @param  int[]  $hesapSalonlari  bulunan hesabin personel + rol satiri olan salonlari (pasif/arsiv dahil)
     */
    public static function baglamaEngeli(array $hesapSalonlari, int $salonId): ?string
    {
        return self::baskaSalonVar($hesapSalonlari, $salonId) ? self::BASKA_ISLETMEDE : null;
    }

    /**
     * @param  int[]  $hesapSalonlari  telefonu degisecek hesabin personel + rol satiri olan salonlari
     * @param  bool   $kendisi         islemi yapan hesabin kendisi
     */
    public static function degisimEngeli(array $hesapSalonlari, int $salonId, bool $kendisi): ?string
    {
        return ! $kendisi && self::baskaSalonVar($hesapSalonlari, $salonId) ? self::DEGISIM_YALNIZ_KENDISI : null;
    }

    /** @param  int[]  $salonlar */
    private static function baskaSalonVar(array $salonlar, int $salonId): bool
    {
        foreach ($salonlar as $s) {
            if ((int) $s !== $salonId) {
                return true;
            }
        }

        return false;
    }
}
