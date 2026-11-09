<?php

namespace App\Domain\Personel;

/**
 * Hesap sahibinin korunmasi — TEK saf kural (cerceve/DB bagimsiz). Web ve mobil ayni servisten
 * (PersonelYazServisi / PersonelArsivServisi) gecer; servisler olgulari (hedef hesap sahibi mi, yapan kim)
 * DB'den cozer, karari burasi verir. Engel mesaji doner (servis 422 atar), engel yoksa null.
 *
 * Hedef "hesap sahibi" = salon_personelleri.role_id 1 YA DA yetkilinin bu salondaki model_has_roles rol 1'i.
 *
 *  - PASIF / ARSIV : hesap sahibi hic pasife alinamaz/arsivlenemez (isletme sahipsiz kalmasin); kimse kendi
 *                    satirini pasife alamaz/arsivleyemez (kendini disarida birakmasin).
 *  - TELEFON       : hesap sahibinin giris telefonu (gsm1 / cep_telefon) yalniz kendisi tarafindan degisir;
 *                    baska bir satirin telefonunu sahibin numarasina baglamak da bu kurala girer.
 *  - SIFRE         : hesap sahibine yeni sifre gonderimi yalniz kendisi tarafindan.
 *  - ROL           : rol 1 satirinin rolu yalniz sahibin kendisi (ya da sistem yonetimi — "salon hesabina gir")
 *                    tarafindan degisir; rol 1 atamak yalniz bu salonun hesap sahibine / sistem yonetimine acik.
 *  - YETKI         : hesap sahibi satirinin yetki ayarlari (PATCH /personel/{id}/yetki) yalniz sahibin kendisi ya da
 *                    sistem yonetimi tarafindan degisir (rol kuraliyla ayni).
 */
final class HesapSahibiKorumasi
{
    public const PASIF = 'pasif';

    public const ARSIV = 'arsiv';

    public const TELEFON = 'telefon';

    public const SIFRE = 'sifre';

    public const ROL = 'rol';

    public const YETKI = 'yetki';

    public const ROL_HESAP_SAHIBI = 1;

    /**
     * @param  bool      $hedefSahip      hedef satir hesap sahibine ait mi
     * @param  int|null  $hedefYetkiliId  hedef satirin giris hesabi (yetkili_id)
     * @param  int|null  $yapanId         islemi yapan yetkili (null = sistem/komut)
     * @param  bool      $sistem          sistem yonetimi oturumu ("salon hesabina gir" token'i)
     */
    public static function engel(string $islem, bool $hedefSahip, ?int $hedefYetkiliId, ?int $yapanId, bool $sistem = false): ?string
    {
        $kendisi = $yapanId !== null && $yapanId > 0 && $hedefYetkiliId === $yapanId;

        return match ($islem) {
            self::PASIF => $hedefSahip ? 'Hesap sahibi pasife alınamaz.'
                : ($kendisi ? 'Kendi personel kaydınızı pasife alamazsınız.' : null),
            self::ARSIV => $hedefSahip ? 'Hesap sahibi arşivlenemez.'
                : ($kendisi ? 'Kendi personel kaydınızı arşivleyemezsiniz.' : null),
            self::TELEFON => $hedefSahip && ! $kendisi ? 'Hesap sahibinin telefonunu yalnız kendisi değiştirebilir.' : null,
            self::SIFRE => $hedefSahip && ! $kendisi ? 'Hesap sahibine şifre yalnız kendisi tarafından gönderilebilir.' : null,
            self::ROL => $hedefSahip && ! $kendisi && ! $sistem ? 'Hesap sahibinin rolünü yalnız kendisi değiştirebilir.' : null,
            self::YETKI => $hedefSahip && ! $kendisi && ! $sistem ? 'Hesap sahibinin yetkilerini yalnız kendisi değiştirebilir.' : null,
            default => throw new \InvalidArgumentException('Bilinmeyen işlem: '.$islem),
        };
    }

    /**
     * Rol atamasi: gecerli rol listesinde mi + rol 1 yalniz hesap sahibi / sistem yonetimi tarafindan atanir.
     * $yeniRol null/'' = rol gonderilmedi (dokunulmaz). $mevcutRol ayniysa degisiklik yok sayilir.
     *
     * @param  int[]  $gecerliRoller  secilebilir rol id'leri (roles tablosu, tek kaynak)
     */
    public static function rolAtamaEngeli(mixed $yeniRol, ?int $mevcutRol, array $gecerliRoller, bool $yapanSahip, bool $sistem = false): ?string
    {
        if ($yeniRol === null || $yeniRol === '') {
            return null;
        }
        if (! (is_int($yeniRol) || (is_string($yeniRol) && ctype_digit($yeniRol))) || ! in_array((int) $yeniRol, array_map('intval', $gecerliRoller), true)) {
            return 'Geçersiz hesap türü.';
        }
        if ((int) $yeniRol === self::ROL_HESAP_SAHIBI && $mevcutRol !== self::ROL_HESAP_SAHIBI && ! $yapanSahip && ! $sistem) {
            return 'Hesap sahibi rolünü yalnız hesap sahibi atayabilir.';
        }

        return null;
    }
}
