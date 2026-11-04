<?php

namespace App\Domain\Randevu;

/**
 * Online randevu MUSAITLIK cekirdegi — SAF (DB yok, dakika tabanli, unit-test edilir).
 *
 * Eski sistemde uc ayri algoritma vardi: web ilk acilis (tarihsaatadiminagec), web tarih
 * degisimi (saatgetir) ve mobil (randevuTarihSaatAdimi). Musterinin gordugu saat ile kaydin
 * kabul ettigi saat ayni kuraldan cikmiyordu. Burada TEK kural: hem saat listesi hem kayit
 * bu sinifi cagirir.
 *
 * Kural: bir baslangic, hizmetler HERHANGI bir siraya dizilince (her kalem kendi ardisik
 * alt-diliminde, adaylarindan bos olan ilk personelle) sigiyorsa musaittir.
 *
 * $kalemler: [['adaylar' => int[] personel, 'sure' => dk, 'hizmet_odalari' => int[]], ...]
 * $takvim  : [
 *   'salon'        => [bas, bit],              // salon acik penceresi (dk)
 *   'salon_mola'   => [[bas, bit], ...],
 *   'pencere'      => [pid => [bas, bit]|null], // kayit yoksa salon penceresi; null = o gun kapali
 *   'dolu'         => [pid => [[bas, bit], ...]],
 *   'personel_oda' => [pid => oda_id],          // personelin kendi odasi (doluysa personel de dolu)
 *   'oda_dolu'     => [oda_id => [[bas, bit], ...]],
 * ]
 */
final class OnlineMusaitlik
{
    /** Permutasyon patlamasini onler: bu sayidan fazla kalemde yalniz verilen sira denenir. */
    private const MAKS_PERMUTASYON_KALEM = 6;

    /**
     * Sigan ilk yerlesim ya da null.
     *
     * @return array<int,array{index:int,personel_id:int,oda_id:?int,bas:int,bit:int,sure:int}>|null
     */
    public static function yerlestir(array $kalemler, int $baslangic, array $takvim): ?array
    {
        $n = count($kalemler);
        if ($n === 0) {
            return null;
        }
        $siralar = $n > self::MAKS_PERMUTASYON_KALEM ? [range(0, $n - 1)] : self::permutasyonlar(range(0, $n - 1));

        foreach ($siralar as $sira) {
            $sonuc = self::siraylaYerlestir($kalemler, $sira, $baslangic, $takvim);
            if ($sonuc !== null) {
                return $sonuc;
            }
        }

        return null;
    }

    /** Salon penceresi boyunca aralik adimli baslangiclar (dk). */
    public static function baslangiclar(int $salonBas, int $salonBit, int $aralikDk): array
    {
        $aralikDk = max(5, $aralikDk);

        return $salonBas < $salonBit ? range($salonBas, $salonBit - 1, $aralikDk) : [];
    }

    public static function ortusur(int $bas, int $bit, array $araliklar): bool
    {
        foreach ($araliklar as [$b, $e]) {
            if ($bas < $e && $bit > $b) {
                return true;
            }
        }

        return false;
    }

    private static function siraylaYerlestir(array $kalemler, array $sira, int $baslangic, array $takvim): ?array
    {
        [$salonBas, $salonBit] = $takvim['salon'];
        $sonuc = [];
        $t = $baslangic;

        foreach ($sira as $idx) {
            $sure = (int) $kalemler[$idx]['sure'];
            $bas = $t;
            $bit = $bas + $sure;
            if ($bas < $salonBas || $bit > $salonBit || self::ortusur($bas, $bit, $takvim['salon_mola'] ?? [])) {
                return null;
            }
            $secim = self::kaynakSec($kalemler[$idx], $bas, $bit, $takvim);
            if ($secim === null) {
                return null;
            }
            $sonuc[] = ['index' => $idx, 'personel_id' => $secim[0], 'oda_id' => $secim[1], 'bas' => $bas, 'bit' => $bit, 'sure' => $sure];
            $t = $bit;
        }

        return $sonuc;
    }

    /** @return array{0:int,1:?int}|null [personel, oda] */
    private static function kaynakSec(array $kalem, int $bas, int $bit, array $takvim): ?array
    {
        foreach ($kalem['adaylar'] as $pid) {
            if (! self::personelBos((int) $pid, $bas, $bit, $takvim)) {
                continue;
            }
            $oda = self::odaSec((int) $pid, $kalem['hizmet_odalari'] ?? [], $bas, $bit, $takvim);
            if ($oda !== false) {
                return [(int) $pid, $oda];
            }
        }

        return null;
    }

    private static function personelBos(int $pid, int $bas, int $bit, array $takvim): bool
    {
        $pencere = array_key_exists($pid, $takvim['pencere'] ?? []) ? $takvim['pencere'][$pid] : $takvim['salon'];
        if ($pencere === null || $bas < $pencere[0] || $bit > $pencere[1]) {
            return false;
        }

        return ! self::ortusur($bas, $bit, $takvim['dolu'][$pid] ?? []);
    }

    /** Oda id, odasiz calisma icin null, oda bulunamazsa false. */
    private static function odaSec(int $pid, array $hizmetOdalari, int $bas, int $bit, array $takvim): int|null|false
    {
        $kendi = $takvim['personel_oda'][$pid] ?? null;
        $adaylar = $kendi ? [(int) $kendi] : $hizmetOdalari;
        if (! $adaylar) {
            return null;
        }
        foreach ($adaylar as $oda) {
            if (! self::ortusur($bas, $bit, $takvim['oda_dolu'][$oda] ?? [])) {
                return (int) $oda;
            }
        }

        return false;
    }

    private static function permutasyonlar(array $dizi): array
    {
        if (count($dizi) <= 1) {
            return [$dizi];
        }
        $sonuc = [];
        foreach ($dizi as $i => $eleman) {
            $kalan = $dizi;
            unset($kalan[$i]);
            foreach (self::permutasyonlar(array_values($kalan)) as $p) {
                $sonuc[] = [$eleman, ...$p];
            }
        }

        return $sonuc;
    }
}
