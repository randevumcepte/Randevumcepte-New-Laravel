<?php

namespace App\Domain\Ozet;

/**
 * Mobil ana ekran kartlarinin SAF hesabi. Formul eski ApiController::ozetsayfasi ve santral_raporlari'ndan
 * satir satir alindi; mobilde gorunen tutar ve sayac bugunkuyle ayni kalmali. Web'in OzetMetrikServisi bu sinifi
 * kullanmaz: web ve mobil tutari bilerek farkli hesaplar.
 */
final class MobilOzetHesap
{
    public const PERSONEL_ROLU = 5;

    /** Bu rolde kasa, kalan tutar ve prim hesaplanmaz; alanlar bos metin kalir. */
    public const KASA_GORMEYEN_ROL = 4;

    /** Bu rolun altinda santral sayaclari okunur. */
    public const SANTRAL_ROL_SINIRI = 4;

    public const MASKE = '***';

    /**
     * Eski foreach bloklari. Personel rolunde yalniz kendi kalemleri toplanir; prim her rolde yalniz kendi kalemindendir.
     * Urunde personel suzgeci `tahsilat_urunler.personel_id` kolonunu okur (eski kod boyle), prim adisyon kaleminden.
     *
     * @param  array<int,array{hizmet:list<object>,urun:list<object>,paket:list<object>}>  $tahsilatlar  sira eski sorguyla ayni
     * @param  object  $personel  id, role_id, hizmet_prim_yuzde, urun_prim_yuzde, paket_prim_yuzde
     * @return array{tutar:float,prim:float}
     */
    public static function tahsilatToplami(array $tahsilatlar, object $personel): array
    {
        $personelRolu = (int) $personel->role_id === self::PERSONEL_ROLU;
        $tutar = 0;
        $prim = 0;

        foreach ($tahsilatlar as $t) {
            foreach ($t['hizmet'] as $o) {
                if (! $personelRolu || (int) ($o->kalem_personel_id ?? 0) === (int) $personel->id) {
                    $tutar += (float) $o->tutar;
                    if (self::kendiKalemi($o, $personel)) {
                        $prim += (float) $o->tutar * ((float) $personel->hizmet_prim_yuzde / 100);
                    }
                }
            }
            foreach ($t['urun'] as $o) {
                if (! $personelRolu || (int) ($o->personel_id ?? 0) === (int) $personel->id) {
                    $tutar += (float) $o->tutar;
                    if (self::kendiKalemi($o, $personel)) {
                        $prim += (float) $o->tutar * ((float) $personel->urun_prim_yuzde / 100);
                    }
                }
            }
            foreach ($t['paket'] as $o) {
                if (! $personelRolu || (int) ($o->kalem_personel_id ?? 0) === (int) $personel->id) {
                    $tutar += (float) $o->tutar;
                    if (self::kendiKalemi($o, $personel)) {
                        $prim += (float) $o->tutar * ((float) $personel->paket_prim_yuzde / 100);
                    }
                }
            }
        }

        return ['tutar' => (float) $tutar, 'prim' => (float) $prim];
    }

    public static function tutarMetni(float $tutar): string
    {
        return number_format($tutar, 2, ',', '.');
    }

    /** Eski santral_raporlari sayaclari; basarisiz giden arama hicbir kartta gosterilmez. */
    public static function aramaSayilari(array $cdrSatirlari): array
    {
        $gelen = 0;
        $giden = 0;
        $cevapsiz = 0;

        foreach ($cdrSatirlari as $s) {
            $baglam = $s['dcontext'] ?? '';
            $sonuc = $s['disposition'] ?? '';
            $yanitsiz = $sonuc === 'NO ANSWER' || $sonuc === 'BUSY';

            if ($baglam === 'from-internal') {
                $giden += $yanitsiz ? 0 : 1;
            } elseif ($baglam === 'ana-menu') {
                $cevapsiz++;
            } elseif ($baglam === 'yol-tarifi' || $baglam === 'kampanya-tanitimyeni') {
                $gelen++;
            } elseif ($baglam === 'ext-local') {
                $yanitsiz ? $cevapsiz++ : $gelen++;
            }
        }

        return ['gelen_arama' => $gelen, 'giden_arama' => $giden, 'cevapsiz_arama' => $cevapsiz];
    }

    /** Eski: 1-5 yildiz satirlarinin ortalamasi, tek ondalik; satir yoksa 0. */
    public static function puan(float $toplam, int $adet): float
    {
        return $adet === 0 ? 0 : round($toplam / $adet, 1);
    }

    private static function kendiKalemi(object $odeme, object $personel): bool
    {
        return isset($odeme->kalem_personel_id) && (int) $odeme->kalem_personel_id === (int) $personel->id;
    }
}
