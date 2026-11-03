<?php

namespace App\Services\BildirimReklam;

use App\Domain\BildirimReklam\HedefKosulu;
use App\Domain\BildirimReklam\ReklamAksiyonu;
use App\Domain\BildirimReklam\ReklamDurumu;
use App\Domain\BildirimReklam\ReklamKuponu;
use App\Domain\BildirimReklam\ReklamTuru;
use App\Support\EskiPanelLinki;
use App\Support\SalonIdListesi;

/**
 * Bildirim reklamı JSON sözleşmesi — TEK yer (web paneli + Flutter yönetici + müşteri uygulaması).
 *
 * yonetim(): eski adminReklamArray alanlarının TAMAMI (Flutter v1 modeli drop-in) + v2 ekleri
 *            (etiket/renk, kupon özeti, hedef nesnesi, şube grubu) — istemciler JSON çözmez,
 *            etiket kopyalamaz.
 * musteri(): eski müşteri liste ucunun alanlarıyla birebir (in-app kart / popup).
 */
final class BildirimReklamSunumu
{
    /** Mutlak görsel URL'i (eski panel host'u) — eski kayıtlar '/public/...' göreli yol tutar. */
    public static function gorselUrl(?string $yol): ?string
    {
        $yol = trim((string) $yol);
        if ($yol === '') {
            return null;
        }

        return preg_match('#^https?://#', $yol) ? $yol : EskiPanelLinki::url($yol);
    }

    /**
     * @param  array{id:int, ad:string, telefon:?string}|null  $kisi  hedef "belirli kişi" ise müşteri özeti
     */
    public static function yonetim(object $r, ?array $kisi = null): array
    {
        $tur = ReklamTuru::dbden($r->tur);
        $durum = ReklamDurumu::dbden($r->durum);
        $kupon = ReklamKuponu::satirdan($r);
        $hedef = HedefKosulu::dbden($r->hedef_kitle, $r->hedef_kosul);

        return [
            'id'                   => (int) $r->id,
            'salon_id'             => (int) $r->salon_id,
            'baslik'               => (string) $r->baslik,
            'mesaj'                => $r->mesaj,
            'gorsel'               => $r->gorsel,
            'gorsel_url'           => self::gorselUrl($r->gorsel),
            'tur'                  => $tur->value,
            'kanal_push'           => (bool) $r->kanal_push,
            'kanal_inapp'          => (bool) $r->kanal_inapp,
            'tam_ekran'            => (bool) $r->tam_ekran,
            'aksiyon_tipi'         => ReklamAksiyonu::dbden($r->aksiyon_tipi)->value,
            'aksiyon_hedef'        => $r->aksiyon_hedef,
            'kupon_indirim_tipi'   => $r->kupon_indirim_tipi,
            'kupon_deger'          => $r->kupon_deger !== null ? (float) $r->kupon_deger : null,
            'kupon_baslik'         => $r->kupon_baslik,
            'kupon_hizmet_id'      => $r->kupon_hizmet_id !== null ? (int) $r->kupon_hizmet_id : null,
            'kupon_urun_id'        => $r->kupon_urun_id !== null ? (int) $r->kupon_urun_id : null,
            'kupon_gecerli_tip'    => $kupon->gecerliTip(),
            'kupon_gecerlilik_gun' => $r->kupon_gecerlilik_gun !== null ? (int) $r->kupon_gecerlilik_gun : null,
            'kupon_kisi_limit'     => (int) $r->kupon_kisi_limit,
            'kupon_toplam_adet'    => $r->kupon_toplam_adet !== null ? (int) $r->kupon_toplam_adet : null,
            'kupon_dagitilan'      => (int) $r->kupon_dagitilan,
            'randevu_tarih'        => $r->randevu_tarih,
            'randevu_tarih_bit'    => $r->randevu_tarih_bit,
            'randevu_saat_bas'     => self::saat($r->randevu_saat_bas),
            'randevu_saat_bit'     => self::saat($r->randevu_saat_bit),
            'hedef_kitle'          => $hedef->segmentMi() ? 'segment' : 'tumu',
            'hedef_kosul'          => $r->hedef_kosul,
            'durum'                => $durum->value,
            'yayin_baslangic'      => $r->yayin_baslangic,
            'yayin_bitis'          => $r->yayin_bitis,
            'push_gonderildi'      => (bool) $r->push_gonderildi,
            'gecerli_salonlar'     => SalonIdListesi::coz($r->gecerli_salonlar ?? null) ?: [(int) $r->salon_id],
            'created_at'           => $r->created_at,

            // v2 ekleri — sunum hazır gelir.
            'tur_etiket'   => ['ad' => $tur->etiket(), 'ikon' => $tur->ikon(), 'renk' => $tur->renk()],
            'durum_etiket' => ['ad' => $durum->etiket(), 'renk' => $durum->renk()],
            'kupon_ozet'   => $kupon->verirMi() ? $kupon->kisaMetin() : null,
            'hedef'        => $hedef->dizi() + ['kisi' => $kisi],
        ];
    }

    /** Müşteri uygulaması kartı (eski /api/v1/reklam/liste öğesi ile aynı alanlar). */
    public static function musteri(object $r): array
    {
        $kupon = ReklamKuponu::satirdan($r);
        $aksiyon = ReklamAksiyonu::dbden($r->aksiyon_tipi);

        return [
            'id'            => (int) $r->id,
            'salon_id'      => (int) $r->salon_id,
            'tur'           => ReklamTuru::dbden($r->tur)->value,
            'baslik'        => (string) $r->baslik,
            'mesaj'         => $r->mesaj,
            'gorsel'        => self::gorselUrl($r->gorsel),
            'tam_ekran'     => (bool) $r->tam_ekran,
            'aksiyon_tipi'  => $aksiyon->value,
            'aksiyon_hedef' => $r->aksiyon_hedef,
            'kupon'         => $kupon->verirMi() ? [
                'indirim_tipi' => $kupon->indirimTipi,
                'deger'        => $kupon->deger,
                'hizmet_id'    => $kupon->hizmetId,
                'urun_id'      => $kupon->urunId,
            ] : null,
            'randevu'       => $aksiyon->randevuPenceresiVar() ? [
                'tarih_bas' => $r->randevu_tarih,
                'tarih_bit' => $r->randevu_tarih_bit,
                'saat_bas'  => self::saat($r->randevu_saat_bas),
                'saat_bit'  => self::saat($r->randevu_saat_bit),
            ] : null,
        ];
    }

    /** "10:00:00" → "10:00" (TIME kolonu); boş → null. */
    private static function saat(?string $s): ?string
    {
        return $s ? substr($s, 0, 5) : null;
    }
}
