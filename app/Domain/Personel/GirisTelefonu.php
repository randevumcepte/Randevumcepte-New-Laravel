<?php

namespace App\Domain\Personel;

/**
 * Giris telefonu (isletmeyetkilileri.gsm1) — TEK saf kural (cerceve/DB bagimsiz). gsm1 giris kimligidir; bir
 * numara tek hesaba aittir ve bir isletme baska isletmenin hesabini ele geciremez. Profil (kendi numarasini
 * degistirme) ve personel ekle/duzenle (web ve mobil ayni servis) GirisTelefonuDenetimi uzerinden buraya gelir;
 * olgulari (numara kimde, hesap hangi salonlarda) servis DB'den cozer. Engel mesaji doner (servis 422), yoksa null.
 *
 *  - NUMARA   : bir hesabin gsm1'i, baska bir hesapta kayitli numaraya cevrilemez (cift giris kimligi olmasin).
 *  - BAGLAMA  : personel satiri telefonla bulunan MEVCUT bir hesaba yalniz o hesabin kayitli oldugu salonlarin hepsi
 *               bu salonun SAHIP GRUBUNDAYSA (ayni hesap sahibinin subeleri) baglanir. Boylece ayni sahibin subelerinde
 *               tek giris hesabiyla calisilir ve subeler arasinda gecilir (eski sistem gibi); baska isletmenin
 *               sahibini/personelini baglamak engellenir.
 *  - DEGISIM  : sahip grubu disindaki salonlarda da kayitli bir hesabin giris telefonunu yalniz hesabin kendisi
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
     * @param  int[]  $sahipGrubu      bu salonla ayni hesap sahibine ait salonlar (bu salon dahil olabilir)
     */
    public static function baglamaEngeli(array $hesapSalonlari, int $salonId, array $sahipGrubu = []): ?string
    {
        return self::grupDisiVar($hesapSalonlari, $salonId, $sahipGrubu) ? self::BASKA_ISLETMEDE : null;
    }

    /**
     * @param  int[]  $hesapSalonlari  telefonu degisecek hesabin personel + rol satiri olan salonlari
     * @param  bool   $kendisi         islemi yapan hesabin kendisi
     * @param  int[]  $sahipGrubu      bu salonla ayni hesap sahibine ait salonlar
     */
    public static function degisimEngeli(array $hesapSalonlari, int $salonId, bool $kendisi, array $sahipGrubu = []): ?string
    {
        return ! $kendisi && self::grupDisiVar($hesapSalonlari, $salonId, $sahipGrubu) ? self::DEGISIM_YALNIZ_KENDISI : null;
    }

    /**
     * @param  int[]  $salonlar
     * @param  int[]  $sahipGrubu
     */
    private static function grupDisiVar(array $salonlar, int $salonId, array $sahipGrubu): bool
    {
        $grup = array_map('intval', $sahipGrubu);
        foreach ($salonlar as $s) {
            if ((int) $s !== $salonId && ! in_array((int) $s, $grup, true)) {
                return true;
            }
        }

        return false;
    }
}
