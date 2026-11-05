<?php

namespace App\Domain\Paket;

use DateTimeImmutable;

/**
 * Sinirsiz / sureli paket kurali — SAF (DB yok, unit-test edilir).
 * Eski monolit karsiligi: App\Services\PaketGecerlilik (iki repoda ayni kural).
 *
 * Sure ILK "Geldi" seansinda baslar. Bitis saklanmaz, hesaplanir: "Geldi" geri alinirsa ya da
 * gecmis tarihli seans girilirse kendiliginden duzelir. Hic gelinmemis pakette sure islemez.
 *
 * Sinirsiz pakette ADET KONTROLU YOKTUR: sure bitene kadar her randevu seansa yazilir, sure
 * bitince yazilmaz. seans_sayisi (ustSinir) yalniz `seans_sayisi > 0` arayan eski sorgular/mobil
 * icin dolu tutulan teknik bir degerdir; KURAL ICIN KULLANILMAZ, EKRANDA GOSTERILMEZ.
 */
final class PaketGecerlilik
{
    public const BIRIM_AY = 'ay';

    public const BIRIM_YIL = 'yil';

    public static function ustSinir(mixed $sayi, ?string $birim, mixed $gunlukLimit): int
    {
        $gun = (int) $sayi * ($birim === self::BIRIM_YIL ? 366 : 31);

        return max(1, $gun) * max(1, (int) $gunlukLimit);
    }

    /** Ilk gelis + sure (Y-m-d). Ay sonu tasmaz: 31 Oca + 1 ay = 28/29 Sub, 29 Sub + 1 yil = 28 Sub. */
    public static function bitis(?string $ilkGeldi, mixed $sayi, ?string $birim): ?string
    {
        if (! $ilkGeldi || (int) $sayi <= 0) {
            return null;
        }
        $ay = (int) $sayi * ($birim === self::BIRIM_YIL ? 12 : 1);
        $bas = new DateTimeImmutable(substr($ilkGeldi, 0, 10));
        $ayBasi = $bas->modify('first day of this month')->modify('+'.$ay.' months');
        $gun = min((int) $bas->format('j'), (int) $ayBasi->format('t'));

        return $ayBasi->format('Y-m-').str_pad((string) $gun, 2, '0', STR_PAD_LEFT);
    }

    /** "6 Ay" / "2 Yıl"; sure yoksa bos. */
    public static function sureMetni(mixed $sayi, ?string $birim): string
    {
        return (int) $sayi > 0 ? (int) $sayi.' '.($birim === self::BIRIM_YIL ? 'Yıl' : 'Ay') : '';
    }

    /** Bitis gununun kendisi dahil gecerli; bitis yoksa (sure yok / hic gelinmedi) hep gecerli. */
    public static function tarihGecerli(?string $bitis, string $tarih): bool
    {
        return $bitis === null || substr($tarih, 0, 10) <= $bitis;
    }
}
