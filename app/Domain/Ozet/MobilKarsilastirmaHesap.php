<?php

namespace App\Domain\Ozet;

use Carbon\Carbon;

/**
 * Mobil "Karsilastirma" kartinin SAF hesabi: donem araliklari, saat yogunlugu, bosluk onerisi. Formul eski
 * ApiController::_periodRanges, _offsetRange, _periodLabel ve _buildHourlyAnalysis ile ayni. Hafta acikca pazartesi
 * baslar: eski surumde varsayilan buydu, guncel Carbon yerele bakar.
 */
final class MobilKarsilastirmaHesap
{
    public const DONEMLER = ['gunluk', 'haftalik', 'aylik', 'yillik'];

    public const VARSAYILAN = 'aylik';

    /** Saat kapama satirlarinin tuttugu sahte musteri. */
    public const SAAT_KAPAMA_MUSTERISI = 2012;

    public const SERI_UZUNLUK = 7;

    private const PAZARTESI = 1;

    private const PAZAR = 0;

    private const GUN_SINIRI = 400;

    public static function donem(?string $donem): string
    {
        return in_array($donem, self::DONEMLER, true) ? $donem : self::VARSAYILAN;
    }

    /** @return array{0:Carbon,1:Carbon,2:Carbon,3:Carbon} bu donem + onceki donem */
    public static function araliklar(string $donem, Carbon $simdi): array
    {
        [$bas, $bit] = self::aralik($donem, $simdi);
        $onceki = match ($donem) {
            'haftalik' => $simdi->copy()->subWeek(),
            'aylik'    => $simdi->copy()->subMonth(),
            'yillik'   => $simdi->copy()->subYear(),
            default    => $simdi->copy()->subDay(),
        };

        return [$bas, $bit, ...self::aralik($donem, $onceki)];
    }

    /** @return array{0:Carbon,1:Carbon} [adim] kadar onceki donem */
    public static function geriAralik(string $donem, int $adim, Carbon $simdi): array
    {
        return self::aralik($donem, match ($donem) {
            'haftalik' => $simdi->copy()->subWeeks($adim),
            'aylik'    => $simdi->copy()->subMonths($adim),
            'yillik'   => $simdi->copy()->subYears($adim),
            default    => $simdi->copy()->subDays($adim),
        });
    }

    public static function etiket(string $donem, bool $simdiki): string
    {
        return [
            'gunluk'   => $simdiki ? 'Bugün' : 'Geçen Aynı Gün',
            'haftalik' => $simdiki ? 'Bu Hafta' : 'Geçen Hafta',
            'aylik'    => $simdiki ? 'Bu Ay' : 'Geçen Ay',
            'yillik'   => $simdiki ? 'Bu Yıl' : 'Geçen Yıl',
        ][$donem] ?? '';
    }

    /** 24 saat, en yogun saate oranli (0-1). @param array<int,int|string> $saatSayilari */
    public static function saatYogunlugu(array $saatSayilari): array
    {
        $enCok = max(array_merge(array_values($saatSayilari), [1]));
        $sonuc = [];
        for ($s = 0; $s < 24; $s++) {
            $sonuc[] = isset($saatSayilari[$s]) ? round($saatSayilari[$s] / $enCok, 2) : 0.0;
        }

        return $sonuc;
    }

    /** @return list<int> donemdeki gunlerin ISO hafta gunleri (1 pazartesi .. 7 pazar) */
    public static function haftaGunleri(Carbon $bas, Carbon $bit): array
    {
        $gunler = [];
        $gun = $bas->copy()->startOfDay();
        $son = $bit->copy()->startOfDay();
        for ($i = 0; $gun->lte($son) && $i < self::GUN_SINIRI; $i++) {
            $gunler[(int) $gun->format('N')] = true;
            $gun->addDay();
        }

        return array_keys($gunler);
    }

    /**
     * @param  array<int,int|string>  $saatSayilari
     * @param  iterable<object>  $calismaSatirlari  baslangic_saati, bitis_saati
     * @param  iterable<object>  $kampanyalar  yeniden eskiye: id, kampanya_baslik, kampanya_aciklama, kampanya_detay, kampanya_bitis_tarihi
     */
    public static function saatAnalizi(array $saatSayilari, iterable $calismaSatirlari, iterable $kampanyalar, string $donem, Carbon $simdi): array
    {
        [$bas, $bit] = self::calismaAraligi($calismaSatirlari);

        $sayilar = [];
        for ($s = $bas; $s < $bit; $s++) {
            $sayilar[$s] = isset($saatSayilari[$s]) ? (int) $saatSayilari[$s] : 0;
        }
        $enCok = max(array_merge(array_values($sayilar), [1]));

        $saatler = [];
        foreach ($sayilar as $s => $adet) {
            $yogunluk = round($adet / $enCok, 2);
            $saatler[] = ['hour' => $s, 'count' => $adet, 'density' => $yogunluk, 'level' => self::seviye($yogunluk)];
        }

        $pik = null;
        $pikAdet = 0;
        foreach ($sayilar as $s => $adet) {
            if ($adet > $pikAdet) {
                $pikAdet = $adet;
                $pik = $s;
            }
        }

        return [
            'workStart'   => $bas,
            'workEnd'     => $bit,
            'peakHour'    => $pik,
            'peakCount'   => $pikAdet,
            'hours'       => $saatler,
            'gaps'        => self::bosluklar($sayilar, $enCok, $bas, $bit, self::bloktakiKampanyalar($kampanyalar), $simdi),
            'hasData'     => $enCok > 1 || $pikAdet > 0,
            'periodLabel' => self::etiket($donem, true),
        ];
    }

    /** @return array{0:Carbon,1:Carbon} */
    private static function aralik(string $donem, Carbon $t): array
    {
        return match ($donem) {
            'haftalik' => [$t->copy()->startOfWeek(self::PAZARTESI), $t->copy()->endOfWeek(self::PAZAR)],
            'aylik'    => [$t->copy()->startOfMonth(), $t->copy()->endOfMonth()],
            'yillik'   => [$t->copy()->startOfYear(), $t->copy()->endOfYear()],
            default    => [$t->copy()->startOfDay(), $t->copy()->endOfDay()],
        };
    }

    /** @return array{0:int,1:int} calisma satiri yoksa 09-20; 20:30 biten gun 21 sayilir. */
    private static function calismaAraligi(iterable $satirlar): array
    {
        $baslar = [];
        $bitler = [];
        foreach ($satirlar as $r) {
            $baslar[] = (int) substr((string) $r->baslangic_saati, 0, 2);
            $bitis = (int) substr((string) $r->bitis_saati, 0, 2);
            $bitler[] = (int) substr((string) $r->bitis_saati, 3, 2) > 0 ? $bitis + 1 : $bitis;
        }
        $bas = $baslar ? min($baslar) : 9;
        $bit = $bitler ? max($bitler) : 20;
        if ($bit <= $bas) {
            $bit = $bas + 1;
        }

        return [max(0, $bas), min(24, $bit)];
    }

    private static function seviye(float $yogunluk): string
    {
        return match (true) {
            $yogunluk >= 0.70 => 'prime',
            $yogunluk >= 0.40 => 'busy',
            $yogunluk >= 0.15 => 'low',
            default           => 'empty',
        };
    }

    /** @return array<string,object> blok anahtari => en yeni aktif kampanya */
    private static function bloktakiKampanyalar(iterable $kampanyalar): array
    {
        $sonuc = [];
        foreach ($kampanyalar as $k) {
            if (preg_match('/\[gap:([a-z]+)\]/', $k->kampanya_detay ?? '', $m) && ! isset($sonuc[$m[1]])) {
                $sonuc[$m[1]] = $k;
            }
        }

        return $sonuc;
    }

    private static function bosluklar(array $sayilar, int $enCok, int $bas, int $bit, array $kampanyalar, Carbon $simdi): array
    {
        $bloklar = [
            ['key' => 'morning', 'label' => 'Sabah', 'from' => $bas, 'to' => 12],
            ['key' => 'afternoon', 'label' => 'Öğleden sonra', 'from' => 12, 'to' => 17],
            ['key' => 'evening', 'label' => 'Akşam', 'from' => 17, 'to' => $bit],
        ];

        $sonuc = [];
        foreach ($bloklar as $b) {
            $from = max($b['from'], $bas);
            $to = min($b['to'], $bit);
            if ($to - $from < 1) {
                continue;
            }

            $toplam = 0;
            $n = 0;
            for ($s = $from; $s < $to; $s++) {
                if (isset($sayilar[$s])) {
                    $toplam += $sayilar[$s] / $enCok;
                    $n++;
                }
            }
            $ort = $n > 0 ? $toplam / $n : 0;
            [$indirim, $durum] = self::oneri($ort);
            $aktif = isset($kampanyalar[$b['key']]) ? self::aktifKampanya($kampanyalar[$b['key']], $simdi) : null;

            $sonuc[] = [
                'key'               => $b['key'],
                'label'             => $b['label'],
                'start'             => $from,
                'end'               => $to,
                'avgDensity'        => round($ort, 2),
                'severity'          => $durum,
                'suggestedDiscount' => $indirim,
                'activeCampaign'    => $aktif,
                'message'           => self::mesaj($b['label'], $from, $to, $ort, $indirim, $aktif),
            ];
        }

        return $sonuc;
    }

    /** @return array{0:int,1:string} */
    private static function oneri(float $ort): array
    {
        return match (true) {
            $ort < 0.15 => [40, 'Çok boş'],
            $ort < 0.30 => [25, 'Boş'],
            $ort < 0.50 => [15, 'Hafif boş'],
            $ort < 0.75 => [0, 'Normal'],
            default     => [0, 'Yoğun'],
        };
    }

    private static function aktifKampanya(object $k, Carbon $simdi): array
    {
        $indirim = 0;
        if (preg_match('/%%?(\d{1,2})\s*indirim/iu', $k->kampanya_aciklama ?? '', $m)) {
            $indirim = (int) $m[1];
        } elseif (preg_match('/%%?(\d{1,2})/u', $k->kampanya_baslik ?? '', $m)) {
            $indirim = (int) $m[1];
        }

        return [
            'id'          => $k->id,
            'baslik'      => $k->kampanya_baslik,
            'bitisTarihi' => $k->kampanya_bitis_tarihi,
            'kalanGun'    => max(0, (int) $simdi->diffInDays(Carbon::parse($k->kampanya_bitis_tarihi), false)),
            'discount'    => $indirim,
        ];
    }

    private static function mesaj(string $etiket, int $from, int $to, float $ort, int $indirim, ?array $aktif): string
    {
        if ($aktif) {
            return sprintf('%s saatleri için %%%d indirimli kampanyanız aktif, %d gün kaldı.', $etiket, $aktif['discount'], $aktif['kalanGun']);
        }
        $doluluk = (int) round($ort * 100);

        return $indirim > 0
            ? sprintf('%s saatleri (%02d:00-%02d:00) ortalama %%%d dolulukta. %%%d indirim kampanyasıyla doldurabilirsiniz.', $etiket, $from, $to, $doluluk, $indirim)
            : sprintf('%s saatleri (%02d:00-%02d:00) ortalama %%%d dolulukta. Kampanya önerisine ihtiyaç yok.', $etiket, $from, $to, $doluluk);
    }
}
