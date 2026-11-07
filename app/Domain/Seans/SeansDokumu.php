<?php

namespace App\Domain\Seans;

/**
 * Seans dokumu PDF'inin hesaplari — SAF. Kaynak: eski StoreAdminController::seansDokumuPdf.
 */
final class SeansDokumu
{
    public const SINIRSIZ = 'Sınırsız';

    /**
     * Her hizmet bir bolgedir; yalniz cihaz verisi girilmis seanslar satir uretir. Seans no bossa
     * bolge icindeki sirasi kullanilir.
     *
     * @param  iterable<array{id:int, hizmet_id:mixed, seans_no:mixed, seans_tarih:mixed}>  $seanslar  seans_tarih, seans_no sirasinda
     * @param  array<int|string,string>  $hizmetAdlari
     * @param  array<int|string,iterable<array<string,mixed>>>  $verilerBySeans  seans id => cihaz satirlari
     * @return list<array{ad:string, satirlar:list<array<string,mixed>>}>
     */
    public static function bolgeler(iterable $seanslar, array $hizmetAdlari, array $verilerBySeans): array
    {
        $gruplar = [];
        foreach ($seanslar as $s) {
            $gruplar[$s['hizmet_id'] ?? ''][] = $s;
        }

        $bolgeler = [];
        foreach ($gruplar as $hizmetId => $grup) {
            usort($grup, fn ($a, $b) => strcmp((string) $a['seans_tarih'], (string) $b['seans_tarih']));
            $satirlar = [];
            foreach ($grup as $i => $s) {
                foreach ($verilerBySeans[$s['id']] ?? [] as $v) {
                    $satirlar[] = [
                        'no'       => $s['seans_no'] ?: $i + 1,
                        'tarih'    => $s['seans_tarih'] ? date('d.m.Y', strtotime((string) $s['seans_tarih'])) : '-',
                        'enerji'   => ($v['enerji'] ?? null) ?: '-',
                        'hiz'      => ($v['hiz'] ?? null) ?: '-',
                        'ms'       => ($v['ms'] ?? null) ?: '-',
                        'atis'     => ($v['atis_sayisi'] ?? null) ?: '-',
                        'personel' => ($v['personel_adi'] ?? null) ?: '-',
                    ];
                }
            }
            if ($satirlar) {
                $bolgeler[] = ['ad' => ($hizmetAdlari[$hizmetId] ?? null) ?: '-', 'satirlar' => $satirlar];
            }
        }

        return $bolgeler;
    }

    /** Paket: seans x hizmet sayisi (hizmet yoksa seans); sinirsiz metin; tek hizmet: seans. */
    public static function toplam(mixed $seansAdedi, bool $paketMi, int $hizmetSayisi, bool $sinirsiz): int|string|null
    {
        if ($paketMi && $sinirsiz) {
            return self::SINIRSIZ;
        }
        if ($seansAdedi === null && ! ($paketMi && $hizmetSayisi > 0)) {
            return null;
        }

        return $paketMi && $hizmetSayisi > 0 ? (int) $seansAdedi * $hizmetSayisi : (int) $seansAdedi;
    }

    /**
     * Gecerli baslangic d.m.Y; bozuksa ilk seans tarihi; o da yoksa '-'.
     *
     * @param  iterable<mixed>  $seansTarihleri
     */
    public static function baslangic(mixed $baslangic, iterable $seansTarihleri): string
    {
        if (self::tarihGecerli($baslangic)) {
            return date('d.m.Y', strtotime((string) $baslangic));
        }
        $ilk = null;
        foreach ($seansTarihleri as $t) {
            if (self::tarihGecerli($t) && ($ilk === null || $t < $ilk)) {
                $ilk = $t;
            }
        }

        return $ilk ? date('d.m.Y', strtotime((string) $ilk)) : '-';
    }

    private static function tarihGecerli(mixed $t): bool
    {
        if (! $t || str_starts_with((string) $t, '0000') || str_starts_with((string) $t, '-')) {
            return false;
        }

        return (int) strtotime((string) $t) > 0;
    }
}
