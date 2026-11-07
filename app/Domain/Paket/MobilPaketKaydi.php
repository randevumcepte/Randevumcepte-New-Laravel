<?php

namespace App\Domain\Paket;

/**
 * Mobil paket formunun yazacagi alanlar — SAF. Kaynak: eski ApiController::paket_ekle_guncelle
 * + App\Services\PaketGecerlilik::paketFormunuYaz. Mobil girilen degeri korur: paket duzeyi fiyat
 * ve seans doluysa o yazilir, bossa hizmet toplami. Web PaketTutari::etkin kuralini kullanir, bu
 * sinif web'de cagrilmaz.
 */
final class MobilPaketKaydi
{
    /**
     * hizmet_id'si olmayan satir atlanir, bos seans ve fiyat 0 yazilir.
     *
     * @return list<array{hizmet_id:int, seans:int, fiyat:int|float}>
     */
    public static function hizmetler(mixed $ham): array
    {
        $sonuc = [];
        foreach (is_array($ham) ? $ham : [] as $h) {
            $hizmetId = is_array($h) && isset($h['hizmet_id']) ? (int) $h['hizmet_id'] : 0;
            if ($hizmetId <= 0) {
                continue;
            }
            $sonuc[] = [
                'hizmet_id' => $hizmetId,
                'seans'     => self::dolu($h['seans'] ?? null) ? (int) $h['seans'] : 0,
                'fiyat'     => self::dolu($h['fiyat'] ?? null) ? (float) $h['fiyat'] : 0,
            ];
        }

        return $sonuc;
    }

    /**
     * paketler satirina yazilacak alanlar.
     *
     * @param  array<string,mixed>  $v  paket formu; hizmetler bu sinifin hizmetler() ciktisidir
     * @return array<string,mixed>
     */
    public static function alanlar(array $v, bool $gecerlilikKolonuVar = true): array
    {
        $hizmetler = $v['hizmetler'] ?? [];
        $alanlar = [
            'fiyat'  => self::dolu($v['fiyat'] ?? null) ? (float) $v['fiyat'] : (float) array_sum(array_column($hizmetler, 'fiyat')),
            'miktar' => self::dolu($v['miktar'] ?? null) ? (int) $v['miktar'] : (int) array_sum(array_column($hizmetler, 'seans')),
        ];
        if (self::dolu($v['sure'] ?? null)) {
            $alanlar['sure'] = $v['sure'];
        }
        if (! $gecerlilikKolonuVar || ! array_key_exists('sinirsiz', $v)) {
            return $alanlar;
        }

        $sinirsiz = (int) $v['sinirsiz'] === 1;
        $birim = ($v['gecerlilik_birim'] ?? null) === PaketGecerlilik::BIRIM_AY ? PaketGecerlilik::BIRIM_AY : PaketGecerlilik::BIRIM_YIL;
        $sayi = min(120, max(0, (int) ($v['gecerlilik_sayi'] ?? 0)));
        if ($sinirsiz && $sayi === 0) {
            $sayi = 1;
            $birim = PaketGecerlilik::BIRIM_YIL;
        }
        $limit = (int) ($v['gunluk_limit'] ?? 0);
        $limit = $sinirsiz && $limit > 0 ? min(50, $limit) : null;

        $alanlar['sinirsiz'] = $sinirsiz ? 1 : 0;
        $alanlar['gecerlilik_sayi'] = $sayi > 0 ? $sayi : null;
        $alanlar['gecerlilik_birim'] = $sayi > 0 ? $birim : null;
        $alanlar['gunluk_limit'] = $limit;
        if ($sinirsiz) {
            $alanlar['miktar'] = PaketGecerlilik::ustSinir($sayi, $birim, $limit);
        }

        return $alanlar;
    }

    private static function dolu(mixed $deger): bool
    {
        return $deger !== null && $deger !== '';
    }
}
