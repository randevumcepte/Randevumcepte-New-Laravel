<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\HedefKitle;
use App\Domain\Kampanya\Indirim;
use App\Domain\Kampanya\KalemSecimi;
use App\Domain\Kampanya\Kanal;

/**
 * Kampanya JSON sözleşmesi (web + Flutter AYNI). Flutter v1 alan adları korunur
 * (paket_isim, hizmet_adi, gorev_turu, katilimci_sayisi, mesaj, indirim_kodu, yuzde_mi/yuzde/xal/yode,
 * baslangic_tarihi/bitis_tarihi, hedef_filtre/hedef_grup/hedef_cinsiyet); ek alanlar yapısal.
 * Durum/rozet/izinli işlemler burada hesaplanır — istemcide iş kuralı yok.
 */
final class KampanyaSunumu
{
    /** @param array{yazabilir:bool} $ctx */
    public static function satir(object $r, array $ctx): array
    {
        $kanal = Kanal::degerden($r->gorev_turu) ?? Kanal::Sms;
        $kalem = KalemSecimi::kayittan($r);
        $kalemAdi = trim((string) ($r->hizmet_adi ?: $r->urun_adi ?: $r->paket_adi));
        $zaman = $r->{$kanal->zamanKolonu()} ?? $r->asistan_tarih_saat;

        return [
            'id'               => (int) $r->id,
            'ad'               => trim((string) $r->paket_isim) ?: ($kalemAdi ?: 'Kampanya'),
            'paket_isim'       => (string) $r->paket_isim,
            'gorev_turu'       => $kanal->value,
            'kanal'            => ['kod' => $kanal->value, 'ad' => $kanal->etiket(), 'kisa' => $kanal->kisaEtiket(), 'ikon' => $kanal->ikon()],
            'hizmet_adi'       => $kalemAdi,
            'kalem'            => $kalem ? ['tur' => $kalem->tur, 'id' => $kalem->id, 'ad' => $kalemAdi, 'deger' => $kalem->deger()] : null,
            'indirim_turu'     => $kanal->indirimli() ? (string) $r->indirim_turu : '',
            'indirim_kodu'     => (string) $r->indirim_kodu,
            'musteri_turu'     => trim((string) $r->musteri_turu),
            'baslangic_tarihi' => self::gun($r->baslangic_tarihi),
            'bitis_tarihi'     => self::gun($r->bitis_tarihi),
            'gonderim_zamani'  => $zaman ? date('Y-m-d H:i', strtotime($zaman)) : null,
            'gonderim_saati'   => $zaman ? date('H:i', strtotime($zaman)) : null,
            'durum'            => self::durum($r, $zaman),
            'katilimci_sayisi' => (int) $r->katilimci_sayisi,
            'ozet'             => [
                'indirim_kullanan' => (int) $r->kullanan, 'katilan' => (int) $r->katilan, 'katilmayan' => (int) $r->katilmayan,
                'ulasilamadi' => (int) $r->ulasilamadi, 'bekleyen' => (int) $r->bekleyen,
            ],
            'islemler'         => ['rapor' => true, 'duzenle' => $ctx['yazabilir'], 'sil' => $ctx['yazabilir']],
        ];
    }

    public static function detay(object $r, array $ctx, bool $aramaAyariAcik): array
    {
        $kanal = Kanal::degerden($r->gorev_turu) ?? Kanal::Sms;
        $hedef = HedefKitle::kayittan($r);
        $indirim = $kanal->indirimli() ? Indirim::metinden($r->indirim_turu) : null;
        $i = ($indirim ?? Indirim::girdiden([]))->sunum();
        $kalem = KalemSecimi::kayittan($r);

        return self::satir($r, $ctx) + [
            'mesaj'             => (string) $r->mesaj,
            'hizmet_urun_paket' => $kalem?->deger() ?? '',
            'hedef_filtre'      => $hedef->filtre,
            'hedef_grup'        => $hedef->grupDegeri(),
            'hedef_cinsiyet'    => $hedef->cinsiyet,
            'hedef_preset'      => $hedef->presetKodu(),
            'indirim'           => $indirim ? $i : null,
            'yuzde_mi'          => $i['yuzde_mi'],
            'yuzde'             => $i['yuzde'],
            'xal'               => $i['xal'],
            'yode'              => $i['yode'],
            'randevu_olustur'   => (int) ($r->randevu_olustur ?? 0) === 1,
            'arama'             => $kanal === Kanal::Arama ? [
                'ayar_acik'       => $aramaAyariAcik,
                'planlanan'       => $r->asistan_tarih_saat ? date('Y-m-d H:i', strtotime($r->asistan_tarih_saat)) : null,
                'sunucu_zamani'   => now()->format('Y-m-d H:i'),
            ] : null,
        ];
    }

    /** @return array{kod:string, ad:string, renk:string} */
    private static function durum(object $r, ?string $zaman): array
    {
        $bugun = now()->toDateString();
        if ($r->bitis_tarihi && self::gun($r->bitis_tarihi) < $bugun) {
            return ['kod' => 'bitti', 'ad' => 'Süresi doldu', 'renk' => 'gri'];
        }
        if ($zaman && strtotime($zaman) > time()) {
            return ['kod' => 'planlandi', 'ad' => 'Planlandı', 'renk' => 'mavi'];
        }

        return ['kod' => 'aktif', 'ad' => 'Yayında', 'renk' => 'yesil'];
    }

    private static function gun(?string $t): ?string
    {
        return $t ? substr($t, 0, 10) : null;
    }
}
