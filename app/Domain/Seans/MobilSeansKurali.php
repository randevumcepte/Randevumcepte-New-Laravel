<?php

namespace App\Domain\Seans;

use DateTime;

/**
 * Mobil seans sayaci ve kalan seans — SAF. Kaynak: eski ApiController::seans_getir,
 * paketVarmiKontroluApi ve seansEkle. Web SeansSayaci kurali farklidir (bekleyen eksiye dusmez,
 * iptal sayilmaz); iki kural karistirilmaz.
 */
final class MobilSeansKurali
{
    /**
     * Normal kalemde bekleyen = bekleyen_seans - gelinen - gelinmeyen, eksi olabilir. Sinirsizda
     * bekleyen = acilmis seans - gelinen - gelinmeyen - telafi. iptal seans gelinmeyene sayilir;
     * hem geldi hem iptal olan seans iki sayaca da girer.
     *
     * @param  iterable<array{geldi?:mixed, iptal?:mixed}>  $seanslar
     * @return array{bekleyen:int, gelinen:int, gelinmeyen:int}
     */
    public static function sayac(iterable $seanslar, mixed $bekleyenSeans, bool $sinirsiz = false): array
    {
        $adet = $gelinen = $gelinmeyen = $telafi = 0;
        foreach ($seanslar as $s) {
            $adet++;
            $geldi = $s['geldi'] ?? null;
            $geldi = $geldi === null ? null : (int) $geldi;
            if ($geldi === 1) {
                $gelinen++;
            }
            if ($geldi === 0 || ! empty($s['iptal'])) {
                $gelinmeyen++;
            }
            if ($geldi === 2) {
                $telafi++;
            }
        }

        return [
            'bekleyen'   => $sinirsiz ? $adet - $gelinen - $gelinmeyen - $telafi : (int) $bekleyenSeans - $gelinen - $gelinmeyen,
            'gelinen'    => $gelinen,
            'gelinmeyen' => $gelinmeyen,
        ];
    }

    /** Tek hizmet kalani = toplam - seans kaydi sayisi; eksiye dusmez. seans_sayisi bossa bekleyen_seans. */
    public static function hizmetKalani(mixed $seansSayisi, mixed $bekleyenSeans, int $kayitSayisi): int
    {
        return max(0, (int) ($seansSayisi ?? $bekleyenSeans ?? 0) - $kayitSayisi);
    }

    /** Paket kalani hizmet basinadir: floor((seans x hizmet sayisi - kayit sayisi) / hizmet sayisi); eksiye dusmez. */
    public static function paketKalani(mixed $seansSayisi, mixed $bekleyenSeans, int $hizmetSayisi, int $kayitSayisi): int
    {
        if ($hizmetSayisi <= 0) {
            return 0;
        }
        $hizmetBasina = (int) ($seansSayisi ?? $bekleyenSeans ?? 0);

        return max(0, (int) floor(($hizmetBasina * $hizmetSayisi - $kayitSayisi) / $hizmetSayisi));
    }

    /** Bos veya Y-m-d olmayan tarih bugune duser (eski surum mobil tarih gondermez). */
    public static function seansTarihi(?string $gelen, string $bugun): string
    {
        if ($gelen === null || $gelen === '') {
            return $bugun;
        }
        $tarih = DateTime::createFromFormat('Y-m-d', $gelen);

        return $tarih && $tarih->format('Y-m-d') === $gelen ? $gelen : $bugun;
    }
}
