<?php

namespace App\Domain\Whatsapp;

/**
 * Tek gruplu sorgu sonucundan (gün + durum → adet) istatistik: bugün / son 7 gün / son 30 gün,
 * haftalık başarı oranı, 30 günlük trend. Saf; eski whatsappOzetData'nın 10 ayrı COUNT'u yerine.
 * Alan adları (bugun, hafta, ay, basariOrani, gunler) v1 ile aynı — Flutter modeli değişmez.
 */
final class GonderimOzeti
{
    public const GUN_SAYISI = 30;

    private const HAFTA_GUN = 7;

    /** @param iterable<object{tarih:string, durum:int|string, adet:int|string}> $satirlar */
    public static function hesapla(iterable $satirlar, string $bugun): array
    {
        $gunler = self::bosGunler($bugun);
        $bugunSayac = ['toplam' => 0, 'basari' => 0, 'fail' => 0, 'fallback' => 0];
        $hafta = ['toplam' => 0, 'basari' => 0];
        $ay = ['toplam' => 0, 'basari' => 0];
        $haftaBasi = self::gunOnce($bugun, self::HAFTA_GUN - 1);

        foreach ($satirlar as $s) {
            $tarih = (string) $s->tarih;
            if (! isset($gunler[$tarih])) {
                continue;
            }
            $durum = GonderimDurumu::tryFrom((int) $s->durum);
            $adet = (int) $s->adet;
            $basari = $durum === GonderimDurumu::Gonderildi ? $adet : 0;

            match ($durum) {
                GonderimDurumu::Gonderildi => $gunler[$tarih]['basari'] += $adet,
                GonderimDurumu::Basarisiz  => $gunler[$tarih]['fail'] += $adet,
                GonderimDurumu::SmsDustu   => $gunler[$tarih]['fallback'] += $adet,
                default                    => null,
            };

            $ay['toplam'] += $adet;
            $ay['basari'] += $basari;
            if ($tarih >= $haftaBasi) {
                $hafta['toplam'] += $adet;
                $hafta['basari'] += $basari;
            }
            if ($tarih === $bugun) {
                $bugunSayac['toplam'] += $adet;
                $bugunSayac['basari'] += $basari;
                $bugunSayac['fail'] += $durum === GonderimDurumu::Basarisiz ? $adet : 0;
                $bugunSayac['fallback'] += $durum === GonderimDurumu::SmsDustu ? $adet : 0;
            }
        }

        return [
            'bugun'       => $bugunSayac,
            'hafta'       => $hafta,
            'ay'          => $ay,
            'basariOrani' => $hafta['toplam'] > 0 ? round($hafta['basari'] / $hafta['toplam'] * 100, 1) : 0,
            'gunler'      => array_values($gunler),
        ];
    }

    /** Sorgunun alt sınırı: trendin ilk günü. */
    public static function ilkGun(string $bugun): string
    {
        return self::gunOnce($bugun, self::GUN_SAYISI - 1);
    }

    /** @return array<string, array{gun:string, basari:int, fail:int, fallback:int}> */
    private static function bosGunler(string $bugun): array
    {
        $gunler = [];
        for ($i = self::GUN_SAYISI - 1; $i >= 0; $i--) {
            $g = self::gunOnce($bugun, $i);
            $gunler[$g] = ['gun' => $g, 'basari' => 0, 'fail' => 0, 'fallback' => 0];
        }

        return $gunler;
    }

    private static function gunOnce(string $bugun, int $gun): string
    {
        return (new \DateTimeImmutable($bugun))->modify("-{$gun} days")->format('Y-m-d');
    }
}
