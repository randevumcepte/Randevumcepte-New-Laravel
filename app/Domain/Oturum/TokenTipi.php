<?php

namespace App\Domain\Oturum;

/**
 * Token'in hesap tipi kurali (SAF). Tip, imzali 'scopes' claim'indedir. Tipli token yalniz kendi
 * guard'inda gecer: musteri numarasi ayni numarali yetkili sayilamaz. Tipsiz (eski) token gecis
 * ayariyla kabul edilir; eski panel kopru token'i (tek tip: yetkili) ayardan bagimsiz isletmede gecer.
 *
 * MOBIL ayri bir kapsamdir, hesap tipinin yanina eklenir: uygulama girisinden cikan token'i tanir. Istekteki
 * mobil bayraklar yalniz bu kapsamla etkilidir (MobilBayraklar). Tipsiz eski token mobil sayilmaz.
 */
final class TokenTipi
{
    public const ISLETME = 'isletme';

    public const MUSTERI = 'musteri';

    public const MOBIL = 'mobil';

    private const TIPLER = [self::ISLETME, self::MUSTERI];

    /** @param  array<int,string>  $kapsam  */
    public static function coz(array $kapsam): ?string
    {
        foreach ($kapsam as $k) {
            if (in_array($k, self::TIPLER, true)) {
                return $k;
            }
        }

        return null;
    }

    /** @param  array<int,string>  $kapsam  */
    public static function mobilMi(array $kapsam): bool
    {
        return self::coz($kapsam) !== null && in_array(self::MOBIL, $kapsam, true);
    }

    /** @return array<int,string> token'a imzalanacak kapsam */
    public static function kapsam(string $tip, bool $mobil): array
    {
        return $mobil ? [$tip, self::MOBIL] : [$tip];
    }

    public static function kabul(?string $tip, string $beklenen, bool $tipsizKabul, ?string $tokenAdi, string $kopruAdi): bool
    {
        if ($tip !== null) {
            return $tip === $beklenen;
        }

        return $tipsizKabul || ($beklenen === self::ISLETME && $tokenAdi === $kopruAdi);
    }
}
