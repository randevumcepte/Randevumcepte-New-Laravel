<?php

namespace Tests\Feature\Yetki;

/**
 * Uc yetki taramasinin (UcYetkiTaramasiTest) izin listesi. Burada OLMAYAN her /api/v2 ucu, bu salonda hicbir yetkisi
 * olmayan personel token'iyla cagrilinca veriye ulasmadan reddetmelidir (401/403/404/422...).
 *
 * Desen: rota sablonu (Route::uri(), 'api/v2/...'), Str::is ile eslesir. Basina yontem yazilabilir: 'GET api/v2/x'.
 * Her girdinin degeri GEREKCEDIR; gerekcesiz girdi eklenmez. Bekci testi eslesmeyen (eskimis) ve gereksiz girdileri
 * raporlar: kimliksiz listede olup kimlik isteyen, musteri listesinde olup musteri guard'inda olmayan, kimlik-yeter
 * listesinde olup zaten 401/403 veren uc.
 */
final class UcYetkiIzinListesi
{
    /**
     * KIMLIKSIZ (herkese acik) uclar: taramada CAGRILMAZ (kod/SMS gonderen uclar var; kimliksiz uclarin korumasi
     * Oturum/RotaKorumaTest'te: liste, siki istek siniri ve imza). Sinif: izinli-acik.
     *
     * @var array<string, string>
     */
    public const KIMLIKSIZ = [
        'api/v2/health'                          => 'Canlilik ucu; veritabani acmaz, kisisel veri yok.',
        'api/v2/telefon/ulkeler'                 => 'Ulke kodlari ve telefon bicimleri; sabit referans veri.',
        'api/v2/vitrin/*'                        => 'Isletme vitrini (musteri sitesi / uygulama on yuzu): kimliksiz, kisisel veri dondurmez; cark uclari siki sinir + kodla.',
        'api/v2/musteri-oturum/*'                => 'Musteri giris / kayit / sifre: kimlik burada alinir; throttle:20,1.',
        'api/v2/oturum/giris'                    => 'Isletme girisi; throttle:20,1.',
        'api/v2/oturum/kod-iste'                 => 'Tek kullanimlik giris kodu (WhatsApp/SMS); throttle:20,1.',
        'api/v2/oturum/kod-dogrula'              => 'Tek kullanimlik kodla giris; throttle:20,1.',
        'api/v2/oturum/sifre-kodu'               => 'Sifre sifirlama kodu; throttle:20,1.',
        'api/v2/oturum/sifre-sifirla'            => 'Sifre sifirlama; throttle:20,1.',
        'api/v2/deneme/*'                        => 'Ucretsiz deneme hesabi acilisi; throttle:10,1 + kod dogrulama.',
        'api/v2/sistem/oturum/giris'             => 'Sistem yonetimi girisi; throttle:20,1.',
        'api/v2/ses/*'                           => 'Seslendirme ses dosyasi: token yerine sureli imza (ses calar baslik gonderemez).',
        'api/v2/form/sablon/{id}/bos-pdf'        => 'Bos form PDF: signed:relative, 5 dk; kisisel veri yok.',
        'api/v2/whatsapp-kontor-sayfa'           => 'Mobil kontor WebView: signed:relative (WebView token basligi gonderemez).',
        'api/v2/whatsapp-kontor-sayfa/talep'     => 'Mobil kontor WebView talebi: signed:relative.',
    ];

    /**
     * MUSTERI TOKENI isteyen uclar (auth:api). Taramada ISLETME personel token'iyla cagrilir ve 401 BEKLENIR: token
     * tipi kapisi (TokenTipi: 'isletme' kapsamli token 'api' guard'inda gecmez; MobilBayraklar mobil token'da da ayni).
     * 401 disi her cevap kirmizidir.
     *
     * @var array<string, string>
     */
    public const MUSTERI_TOKENI = [
        'api/v2/musteri-uygulama/*' => 'Musteri uygulamasi: kimlik musteri token\'indan (users); isletme token\'i girememeli.',
    ];

    /**
     * KIMLIK YETER: giris yapan her isletme kullanicisina (yetki anahtari aranmadan) acik olmasi BILINCLI uclar.
     * Taramada cagrilir; sonuc ne olursa olsun izinli-acik sayilir. Hepsi 401/403 verirse girdi gereksizdir (bekci).
     *
     * @var array<string, string>
     */
    public const KIMLIK_YETER = [
        'api/v2/oturum/cikis'          => 'Kendi oturumunu kapatir (auth:isletmeyonetim-api,api).',
        'api/v2/oturum/yenile'         => 'Kendi token\'ini yeniler.',
        'api/v2/kabuk'                 => 'Panel kabugu (menu yetkiye gore suzulur); baglamSalonu ile kapsam. Lisans muaf.',
        'api/v2/kabuk/sms'             => 'Header rozeti; personel rolune null doner (IletisimDurumServisi::gorebilir).',
        'api/v2/kabuk/whatsapp'        => 'Header rozeti; personel rolune null doner (IletisimDurumServisi::gorebilir).',
        'api/v2/profil'                => 'Kullanicinin kendi profili.',
        'api/v2/profil/*'              => 'Kullanicinin kendi profil resmi.',
        'api/v2/personel-yetki/benim'  => 'Kullanicinin kendi efektif yetkileri (mobil kabuk acilista okur).',
        'api/v2/personel-yetki/sema'   => 'Sabit yetki semasi (anahtar listesi); salon verisi yok.',
        'api/v2/bildirim'              => 'Kendi bildirim kutusu (yetkili + salon bazinda).',
        'api/v2/bildirim/*'            => 'Kendi bildirimleri, push cihaz / VoIP kaydi, web push genel anahtari.',
        'api/v2/ajanda'                => 'Kendi ajanda notlari (ajanda_olusturan = kendi personel id).',
        'api/v2/ajanda/*'              => 'Kendi ajanda notlari.',
        'api/v2/masraf/kategoriler'    => 'Tum salonlara ortak sabit kategori listesi.',
        'api/v2/secici/*'              => 'Form secicileri (hizmet, oda, cihaz, sehir): salonun her uyesinin gordugu referans veri, kapsam yeter.',
    ];
}
