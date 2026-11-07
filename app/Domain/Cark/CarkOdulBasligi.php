<?php

namespace App\Domain\Cark;

/**
 * Musteriye gosterilen odul basligi (SAF). Eski CarkifelekApiController::baslikUret ile ayni metinler:
 * musteri uygulamasinda kazanilan odulun adi ve kupon basligi bundan gelir.
 */
final class CarkOdulBasligi
{
    private const INDIRIMLER = [DilimTipi::HIZMET, DilimTipi::URUN, DilimTipi::PAKET];

    public static function uret(string $tip, ?float $deger, ?string $ad, ?string $indirimTipi): string
    {
        if ($tip === DilimTipi::PUAN) {
            return $deger ? ((int) $deger).' Puan' : 'Puan';
        }
        $ad = trim((string) $ad);
        if ($ad !== '') {
            $ek = in_array($tip, self::INDIRIMLER, true) && $deger ? self::indirimEki($deger, $indirimTipi) : '';

            return $ek !== '' && ! preg_match('/%|₺|\bTL\b|indirim|bedava/iu', $ad) ? $ad.' · '.$ek : $ad;
        }

        return match ($tip) {
            DilimTipi::HIZMET => self::indirim($deger, $indirimTipi, 'Hizmet İndirimi', 'Hizmet'),
            DilimTipi::URUN   => self::indirim($deger, $indirimTipi, 'Ürün İndirimi', 'Ürün'),
            DilimTipi::PAKET  => self::indirim($deger, $indirimTipi, 'Paket İndirimi', 'Paket'),
            DilimTipi::TEKRAR => 'Tekrar Dene',
            DilimTipi::BOS    => 'Boş',
            default           => 'Ödül',
        };
    }

    private static function indirimEki(float $deger, ?string $indirimTipi): string
    {
        if ($deger <= 0) {
            return '';
        }
        if ($indirimTipi === DilimTipi::TUTAR) {
            return ((int) $deger).'₺ İndirim';
        }

        return (int) $deger >= 100 ? 'Bedava' : '%'.((int) $deger).' İndirim';
    }

    private static function indirim(?float $deger, ?string $indirimTipi, string $tam, string $kisa): string
    {
        if (! $deger) {
            return $tam;
        }
        if ($indirimTipi === DilimTipi::TUTAR) {
            return ((int) $deger).' ₺ '.$tam;
        }

        return (int) $deger >= 100 ? 'Bedava '.$kisa : '%'.((int) $deger).' '.$tam;
    }
}
