<?php

namespace App\Support\Yetki;

/**
 * Birden cok ucun (web ve mobil dal) ayni is icin istedigi yetki listeleri — TEK kaynak.
 *
 * Kural (2026-11-12): mobil ve web ayni yetki kuralini kullanir; mobil dalin web'den tek farki cevap bicimi ve
 * token kapisidir. Ayni isi yapan uclar listeyi buradan okur, ikinci kopya yazilmaz. Liste ici VEYA'dir
 * (GuvenlikKapisi::denetle); iki listenin birlikte gerektigi islerde iki ayri denetim yapilir.
 */
final class IslemYetkileri
{
    /** Satis (adisyon) listesi, adisyon ve katalog gorme (eski satisListeYetkiYoksa403). */
    public const SATIS_GOR = ['satis.tum_satis_gor', 'satis.adisyon_olustur', 'satis.tahsilat_al'];

    /** Adisyon acma ve kalem ekleme / duzenleme / silme. */
    public const ADISYON_DUZENLE = ['satis.adisyon_olustur'];

    /** Tahsilat alma (kampanya kodu, cark kuponu uygulama dahil). */
    public const TAHSILAT = ['satis.tahsilat_al'];

    /** Taksitli satis / senet plani. */
    public const TAKSIT = ['satis.senet_olustur'];

    /** Alacak / senet yonetimi ve listeleri. */
    public const ALACAK = ['finans.alacak_yonet'];

    /** Urun satisi (stok hizli satis). */
    public const URUN_SAT = ['urun.sat'];

    /** Randevu formu verileri (secenekler, musteri aramasi, kalan seans). */
    public const RANDEVU_FORM = ['randevu.olustur', 'randevu.duzenle_iptal'];
}
