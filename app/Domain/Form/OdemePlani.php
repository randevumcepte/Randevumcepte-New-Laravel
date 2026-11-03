<?php

namespace App\Domain\Form;

/**
 * Sözleşme taksit planı — SAF. Eski ApiController@_sozlesmeOdemePlaniUretAPI ile
 * birebir: kalan = toplam − kapora; birim = verilen taksit tutarı ya da kalan/n;
 * son taksit yuvarlama farkını taşır; tarih ilk taksitten aylık ilerler.
 * Hesap kuruş (integer) üzerinden yapılır ki yuvarlama birikmesin.
 */
final class OdemePlani
{
    /**
     * @return array{taksit_tutari:float,plan:array<int,array{sira:int,tarih:?string,tutar:float}>}
     */
    public static function uret(float $toplam, float $kapora, int $taksitSayisi, ?float $taksitTutari = null, ?string $ilkTarih = null): array
    {
        $n = max(1, $taksitSayisi);
        $kalan = max(0, (int) round($toplam * 100) - (int) round($kapora * 100));
        $birim = ($taksitTutari !== null && $taksitTutari > 0)
            ? (int) round($taksitTutari * 100)
            : (int) round($kalan / $n);

        $tarih = null;
        if ($ilkTarih) {
            try {
                $tarih = new \DateTimeImmutable($ilkTarih);
            } catch (\Exception) {
                $tarih = null;
            }
        }

        $plan = [];
        $toplanan = 0;
        for ($i = 1; $i <= $n; $i++) {
            $tutar = max(0, $i === $n ? $kalan - $toplanan : $birim);
            $toplanan += $tutar;
            $plan[] = [
                'sira'  => $i,
                'tarih' => $tarih ? self::ayEkle($tarih, $i - 1)->format('Y-m-d') : null,
                'tutar' => round($tutar / 100, 2),
            ];
        }

        return ['taksit_tutari' => round($birim / 100, 2), 'plan' => $plan];
    }

    /** Carbon addMonths ile aynı (ay taşması: 31 Oca + 1 ay = 3 Mar). */
    private static function ayEkle(\DateTimeImmutable $t, int $ay): \DateTimeImmutable
    {
        return $ay === 0 ? $t : $t->modify('+'.$ay.' month');
    }
}
