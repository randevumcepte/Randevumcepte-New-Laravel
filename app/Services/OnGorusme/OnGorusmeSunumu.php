<?php

namespace App\Services\OnGorusme;

use App\Domain\Musteri\MusteriSozluk;
use App\Domain\OnGorusme\GorusmeDurumu;
use App\Domain\OnGorusme\GorusmeNedeni;
use App\Support\Yetki\YetkiSabitleri;

/**
 * Ön görüşme JSON sözleşmesi — web (/v2/ongorusmeler) ve Flutter AYNI biçimi okur.
 *
 * Alan adları Flutter'ın mevcut OnGorusme.fromJson'u ile uyumlu (drop-in):
 *   id, ad_soyad, cep_telefon, email, cinsiyet, adres, meslek, il_id, aciklama, musteri_tipi,
 *   hatirlatma_tarihi, on_gorusme_saati, durum (ham: null|0|1), satisyapilmadi_not,
 *   paket_id / urun_id / hizmet_id, oda_id, musteri{id,name}, personel{id,personel_adi},
 *   paket{id,paket_adi,fiyat,miktar,satis_seans,seans_araligi,sinirsiz,gecerlilik_*,hizmetler[]},
 *   urun{id,urun_adi,fiyat}, hizmet{id,hizmet_adi}
 * v2 ekleri (istemci yeniden hesaplamaz): durum_kodu / durum_adi, musteri_tipi_adi,
 * neden{tur, id, anahtar, etiket, ad}, randevu_id, islemler{duzenle, sonuclandir}.
 * Telefon 'musteri.telefon_gor' yetkisi yoksa MASKELİ (KVKK veri minimizasyonu).
 */
final class OnGorusmeSunumu
{
    /**
     * @param  object  $r  OnGorusmeSorgusu::temelSorgu() satırı
     * @param  array{telefon_gor:bool, yazabilir:bool}  $ctx
     */
    public static function satir(object $r, array $ctx): array
    {
        $durum = GorusmeDurumu::dbden($r->durum);
        $neden = GorusmeNedeni::kolonlardan($r);
        $nedenAd = match ($neden?->tur) {
            GorusmeNedeni::PAKET  => $r->paket_adi,
            GorusmeNedeni::URUN   => $r->urun_adi,
            GorusmeNedeni::HIZMET => $r->hizmet_adi,
            default               => null,
        };
        $tel = (string) $r->cep_telefon;
        $acik = $durum->acikMi() && $ctx['yazabilir'];

        return [
            'id'                 => (int) $r->id,
            'ad_soyad'           => (string) $r->ad_soyad,
            'cep_telefon'        => $ctx['telefon_gor'] ? $tel : YetkiSabitleri::telefonMaskele($tel),
            'email'              => $r->email ?: null,
            'cinsiyet'           => self::sayiVeyaNull($r->cinsiyet),
            'adres'              => $r->adres ?: null,
            'meslek'             => $r->meslek ?: null,
            'il_id'              => self::sayiVeyaNull($r->il_id),
            'aciklama'           => $r->aciklama ?: null,
            'musteri_tipi'       => self::sayiVeyaNull($r->musteri_tipi),
            'musteri_tipi_adi'   => MusteriSozluk::referansAdi($r->musteri_tipi),
            // Randevu tarih/saati kanonik (takvimden sürüklenince randevu değişir); yoksa görüşme kaydı.
            'hatirlatma_tarihi'  => self::tarih($r->randevu_tarih ?? $r->hatirlatma_tarihi),
            'on_gorusme_saati'   => self::saat($r->randevu_saat ?? $r->on_gorusme_saati),
            'durum'              => $durum->db(),
            'durum_kodu'         => $durum->value,
            'durum_adi'          => $durum->etiket(),
            'satisyapilmadi_not' => $r->satisyapilmadi_not ?: null,
            'neden'              => $neden ? ['tur' => $neden->tur, 'id' => $neden->id, 'anahtar' => $neden->anahtar(),
                'etiket' => $neden->etiket(), 'ad' => $nedenAd !== null ? trim((string) $nedenAd) : null] : null,
            'paket_id'           => self::sayiVeyaNull($r->paket_id),
            'urun_id'            => self::sayiVeyaNull($r->urun_id),
            'hizmet_id'          => self::sayiVeyaNull($r->hizmet_id),
            // paket: {id, paket_adi} + satis varsayilanlari (OnGorusmeSorgusu::paketOzetleri — fiyat, miktar, satis_seans,
            // seans_araligi, sinirsiz, gecerlilik_*, hizmetler[{hizmet_id, seans, fiyat}]); urun: birim fiyat.
            'paket'              => $r->paket_id ? ['id' => (int) $r->paket_id, 'paket_adi' => $r->paket_adi] + ($r->paket_ozeti ?? []) : null,
            'urun'               => $r->urun_id ? ['id' => (int) $r->urun_id, 'urun_adi' => $r->urun_adi]
                + (isset($r->urun_fiyat) ? ['fiyat' => (float) $r->urun_fiyat] : []) : null,
            'hizmet'             => $r->hizmet_id ? ['id' => (int) $r->hizmet_id, 'hizmet_adi' => $r->hizmet_adi] : null,
            'user_id'            => self::sayiVeyaNull($r->user_id),
            'musteri'            => $r->user_id ? ['id' => (int) $r->user_id, 'name' => $r->musteri_adi ?: $r->ad_soyad] : null,
            'personel_id'        => self::sayiVeyaNull($r->personel_id),
            'personel'           => $r->personel_id ? ['id' => (int) $r->personel_id, 'personel_adi' => $r->personel_adi] : null,
            'randevu_id'         => self::sayiVeyaNull($r->randevu_id),
            'oda_id'             => self::sayiVeyaNull($r->oda_id),
            'created_at'         => $r->created_at ? (string) $r->created_at : null,
            'islemler'           => ['duzenle' => $acik, 'sonuclandir' => $acik],
        ];
    }

    private static function sayiVeyaNull(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }

    private static function tarih(mixed $v): ?string
    {
        return $v ? substr((string) $v, 0, 10) : null;
    }

    private static function saat(mixed $v): ?string
    {
        return $v ? substr((string) $v, 0, 5) : null;
    }
}
