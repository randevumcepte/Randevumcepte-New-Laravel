<?php

namespace App\Domain\SistemYonetim;

/**
 * Salon saglik skoru (0-100, yuksek = saglikli). Saf hesap: sinyalleri cagiran toplar (tek salon ya da toplu sorgu);
 * puanlama yalniz burada.
 */
final class SaglikSkoru
{
    /** Tarihi hic olmayan sinyalin "kac gundur yok" degeri: tum esiklerin disinda kalir. */
    private const HIC_YOK = 999;

    /**
     * @param  array{son_giris?:?string,son_randevu?:?string,hacim_30g?:int,acik_ticket?:int,sikayet?:int}  $sinyaller
     * @param  int|null  $simdi  unix zamani (test icin); verilmezse su an
     * @return array{skor:int,durum:string,sebepler:list<string>}
     */
    public static function hesapla(array $sinyaller, bool $whatsappAktif, ?int $simdi = null): array
    {
        $simdi ??= time();
        $sebepler = [];

        $skor = self::girisPuani(self::gunFarki($sinyaller['son_giris'] ?? null, $simdi), $sebepler)
            + self::randevuPuani(self::gunFarki($sinyaller['son_randevu'] ?? null, $simdi), $sebepler)
            + self::hacimPuani((int) ($sinyaller['hacim_30g'] ?? 0), $sebepler)
            - self::talepCezasi((int) ($sinyaller['acik_ticket'] ?? 0), $sebepler)
            - self::sikayetCezasi((int) ($sinyaller['sikayet'] ?? 0), $sebepler)
            + ($whatsappAktif ? 5 : 0);

        $skor = max(0, min(100, $skor));

        return ['skor' => $skor, 'durum' => self::durum($skor), 'sebepler' => $sebepler];
    }

    /** Askidaki salon sinyal aranmadan kritiktir. */
    public static function askida(?string $sebep): array
    {
        return ['skor' => 0, 'durum' => 'kritik', 'sebepler' => ['Salon askıda: '.($sebep ?: 'sebep yok')]];
    }

    public static function durum(int $skor): string
    {
        return match (true) {
            $skor < 25 => 'kritik',
            $skor < 50 => 'riskli',
            $skor < 75 => 'orta',
            default    => 'iyi',
        };
    }

    private static function gunFarki(?string $tarih, int $simdi): int
    {
        $zaman = $tarih ? strtotime($tarih) : false;

        return $zaman === false ? self::HIC_YOK : (int) floor(($simdi - $zaman) / 86400);
    }

    private static function girisPuani(int $gun, array &$sebepler): int
    {
        if ($gun <= 7) {
            return 25;
        }
        if ($gun <= 30) {
            $sebepler[] = "Son giriş {$gun} gün önce";

            return 18;
        }
        if ($gun <= 90) {
            $sebepler[] = "Son giriş {$gun} gün önce — riskli";

            return 8;
        }
        $sebepler[] = 'Hesaba 90+ gündür giriş yok';

        return 0;
    }

    private static function randevuPuani(int $gun, array &$sebepler): int
    {
        if ($gun <= 3) {
            return 30;
        }
        if ($gun <= 7) {
            return 24;
        }
        if ($gun <= 30) {
            $sebepler[] = "Son randevu {$gun} gün önce";

            return 14;
        }
        if ($gun <= 90) {
            $sebepler[] = "Son randevu {$gun} gün önce — riskli";

            return 5;
        }
        $sebepler[] = '90+ gündür randevu eklenmemiş';

        return 0;
    }

    private static function hacimPuani(int $hacim, array &$sebepler): int
    {
        if ($hacim >= 100) {
            return 20;
        }
        if ($hacim >= 50) {
            return 16;
        }
        if ($hacim >= 20) {
            return 12;
        }
        if ($hacim >= 5) {
            return 6;
        }
        if ($hacim > 0) {
            $sebepler[] = "30 günde sadece {$hacim} randevu";

            return 2;
        }
        $sebepler[] = '30 günde hiç randevu yok';

        return 0;
    }

    private static function talepCezasi(int $adet, array &$sebepler): int
    {
        if ($adet <= 0) {
            return 0;
        }
        $sebepler[] = "{$adet} açık talep var";

        return min(15, $adet * 5);
    }

    private static function sikayetCezasi(int $adet, array &$sebepler): int
    {
        if ($adet <= 0) {
            return 0;
        }
        $sebepler[] = "Son 30 günde {$adet} şikayet kaydı";

        return min(10, $adet * 5);
    }
}
