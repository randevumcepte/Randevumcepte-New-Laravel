<?php

namespace App\Services\Stok;

use App\Domain\Stok\Birim;
use App\Domain\Stok\HareketTipi;
use App\Domain\Stok\StokSeviyesi;
use App\Domain\Stok\UrunTipi;
use App\Models\Depo;
use App\Models\HizmetSarfRecetesi;
use App\Models\StokHareketi;
use App\Models\Tedarikci;
use App\Models\UrunKategorisi;
use App\Models\Urunler;

/**
 * Stok JSON sozlesmesi — TEK bicim (web + Flutter). Alan adlari eski /api/stok/*
 * cevaplariyla ve Flutter modelleriyle (Urun, Depo, Tedarikci, UrunKategorisi,
 * StokHareketi .fromJson) AYNI; v2 farki: sayilar ham (int/float) doner — Flutter
 * modelleri her alani toString() ile okudugundan uyumludur.
 *
 * Ek (sunucuda hesaplanan, istemci yeniden hesaplamaz): stok_seviyesi + stok_renk,
 * tip_etiket, birim_etiket, hareket etiket/renk.
 */
final class StokSunumu
{
    public static function urun(Urunler $u): array
    {
        $stok = (float) $u->stok_adedi;
        $dusuk = self::sayiVeyaNull($u->dusuk_stok_siniri);
        $kritik = self::sayiVeyaNull($u->kritik_stok_siniri);
        $seviye = StokSeviyesi::hesapla($stok, $dusuk, $kritik);
        $kat = $u->relationLoaded('kategori') ? $u->kategori : null;
        $ted = $u->relationLoaded('tedarikci') ? $u->tedarikci : null;

        return [
            'id'                 => (int) $u->id,
            'salon_id'           => (int) $u->salon_id,
            'urun_adi'           => (string) $u->urun_adi,
            'barkod'             => (string) ($u->barkod ?? ''),
            'sku'                => (string) ($u->sku ?? ''),
            'fiyat'              => (float) $u->fiyat,
            'alis_fiyati'        => self::sayiVeyaNull($u->alis_fiyati),
            'kdv_orani'          => self::sayiVeyaNull($u->kdv_orani),
            'birim'              => (string) ($u->birim ?: Birim::Adet->value),
            'birim_etiket'       => Birim::tryFrom((string) $u->birim)?->etiket() ?? (string) $u->birim,
            'tip'                => (string) ($u->tip ?: UrunTipi::Satis->value),
            'tip_etiket'         => UrunTipi::tryFrom((string) $u->tip)?->etiket() ?? (string) $u->tip,
            'kategori_id'        => $u->kategori_id ? (int) $u->kategori_id : null,
            'kategori_adi'       => (string) ($kat?->ad ?? ''),
            'kategori_renk'      => (string) ($kat?->renk ?? ''),
            'kategori_ikon'      => (string) ($kat?->ikon ?? ''),
            'tedarikci_id'       => $u->tedarikci_id ? (int) $u->tedarikci_id : null,
            'tedarikci_adi'      => (string) ($ted?->ad ?? ''),
            'varsayilan_depo_id' => $u->varsayilan_depo_id ? (int) $u->varsayilan_depo_id : null,
            'aktif'              => (bool) $u->aktif,
            'stok_adedi'         => $stok,
            'dusuk_stok_siniri'  => $dusuk,
            'kritik_stok_siniri' => $kritik,
            'stok_seviyesi'      => $seviye,
            'stok_renk'          => StokSeviyesi::renk($seviye),
            'resim_url'          => (string) ($u->resim_url ?? ''),
            'aciklama'           => (string) ($u->aciklama ?? ''),
        ];
    }

    public static function kategori(UrunKategorisi $k): array
    {
        return [
            'id'       => (int) $k->id,
            'salon_id' => (int) $k->salon_id,
            'ad'       => (string) $k->ad,
            'ikon'     => (string) ($k->ikon ?? ''),
            'renk'     => (string) ($k->renk ?? ''),
            'sira'     => (int) $k->sira,
            'aktif'    => (bool) $k->aktif,
        ];
    }

    public static function depo(Depo $d): array
    {
        return [
            'id'          => (int) $d->id,
            'salon_id'    => (int) $d->salon_id,
            'depo_adi'    => (string) $d->depo_adi,
            'aciklama'    => (string) ($d->aciklama ?? ''),
            'varsayilan'  => (bool) $d->varsayilan,
            'aktif'       => (bool) $d->aktif,
            'toplam_stok' => (float) ($d->toplam_stok ?? 0),
        ];
    }

    public static function tedarikci(Tedarikci $t): array
    {
        return [
            'id'       => (int) $t->id,
            'salon_id' => (int) $t->salon_id,
            'ad'       => (string) $t->ad,
            'telefon'  => (string) ($t->telefon ?? ''),
            'vergi_no' => (string) ($t->vergi_no ?? ''),
            'email'    => (string) ($t->email ?? ''),
            'adres'    => (string) ($t->adres ?? ''),
            'aciklama' => (string) ($t->aciklama ?? ''),
            'aktif'    => (bool) $t->aktif,
        ];
    }

    public static function recete(HizmetSarfRecetesi $r): array
    {
        $urun = $r->relationLoaded('urun') ? $r->urun : null;

        return [
            'id'          => (int) $r->id,
            'salon_id'    => (int) $r->salon_id,
            'hizmet_id'   => (int) $r->hizmet_id,
            'hizmet_tipi' => (string) $r->hizmet_tipi,
            'urun_id'     => (int) $r->urun_id,
            'urun_adi'    => (string) ($urun?->urun_adi ?? ''),
            'birim'       => (string) ($urun?->birim ?? ''),
            'miktar'      => (float) $r->miktar,
            'aktif'       => (bool) $r->aktif,
        ];
    }

    /** $h: StokHareketi (+ opsiyonel join alanlari urun_adi / depo_adi). */
    public static function hareket(StokHareketi $h): array
    {
        $tip = HareketTipi::sunum($h->hareket_tipi);

        return [
            'id'                 => (int) $h->id,
            'urun_id'            => (int) $h->urun_id,
            'urun_adi'           => (string) ($h->urun_adi ?? ''),
            'depo_id'            => $h->depo_id ? (int) $h->depo_id : null,
            'depo_adi'           => (string) ($h->depo_adi ?? ''),
            'miktar'             => (float) $h->miktar,
            'hareket_tipi'       => (string) $h->hareket_tipi,
            'hareket_etiket'     => $tip['etiket'],
            'hareket_renk'       => $tip['renk'],
            'referans_tip'       => $h->referans_tip,
            'referans_id'        => $h->referans_id ? (int) $h->referans_id : null,
            'batch_uuid'         => $h->batch_uuid,
            'birim_alis_fiyati'  => self::sayiVeyaNull($h->birim_alis_fiyati),
            'birim_satis_fiyati' => self::sayiVeyaNull($h->birim_satis_fiyati),
            'aciklama'           => (string) ($h->aciklama ?? ''),
            'kullanici_id'       => $h->kullanici_id ? (int) $h->kullanici_id : null,
            'kullanici_tipi'     => $h->kullanici_tipi,
            'tarih'              => $h->tarih?->format('Y-m-d H:i:s'),
        ];
    }

    private static function sayiVeyaNull(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }
}
