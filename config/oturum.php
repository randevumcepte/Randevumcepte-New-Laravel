<?php

return [
    /*
     | Giris, sifre sifirlama ve ucretsiz deneme (web + Flutter tek uc). docs/strangler-oturum.md
     */

    // Ana uygulama: tum markalarin yetkilileri girer, yalniz uyelik_turu 3 disi salonlar gosterilir (v1 ile ayni).
    'ana_uygulama_bundle' => 'com.randevumcepte.randevumcepte',

    // Apple/Google inceleme hesaplari: sifreleri sifirlanamaz, SMS gitmez.
    'korunan_hesaplar' => ['isletme' => [3], 'musteri' => [123244]],

    // Tipsiz token gecisi: yeni uygulama yeni girise gecene kadar acik; sonra OTURUM_TIPSIZ_TOKEN_KABUL=false.
    'tipsiz_token_kabul' => (bool) env('OTURUM_TIPSIZ_TOKEN_KABUL', true),
    'kopru_token_adi'    => 'v2-web',

    // Yetki veya dogrulamayi gevseten ya da eski mobil sonucunu secen istek alanlari. Yalniz imzali mobil token'la
    // etkilidir: diger her istekte (web token'i, musteri web token'i, kimliksiz) controller'a ulasmadan silinir
    // (Services/Oturum/MobilBayraklar). Web bu alanlari gondermez. Yeni mobil bayrak BURAYA eklenir.
    'mobil_bayraklari' => [
        'mobil', 'mobil_gorunum', 'mobil_sablon', 'mobil_sozlesme', 'musteri_karti', 'sekme_yetkisi', 'personel_rolu',
        'gece_devri', 'yalniz', 'liste_yetkisi', 'liste_menusu', 'yalniz_salon', 'kara_liste_atla', 'genel_link', 'sesli_musteri',
    ],

    // Kod ve deneme mesajlari once sistem WhatsApp hattindan gider (false: yalniz SMS).
    'kod_whatsapp' => (bool) env('OTURUM_KOD_WHATSAPP', true),

    // Tek kullanimlik kodla giris.
    'tek_kullanimlik' => [
        'hane'          => 6,
        'sure_sn'       => 300,
        'yeniden_sn'    => 60,
        'tel_saatlik'   => 5,
        'ip_saatlik'    => 30,
        'inceleme_kodu' => env('OTURUM_INCELEME_KODU'),
    ],

    // /api/v2 genel istek siniri: IP + kimlik basina dakikada (eski Kernel api grubu ile ayni). AppServiceProvider.
    'genel_istek_dakika' => (int) env('API_GENEL_ISTEK_DAKIKA', 300),

    // Token bitimine bu kadar gun kala /oturum/yenile yeni token verir.
    'token_yenile_gun' => 30,

    'giris_deneme'         => 10,
    'giris_deneme_sn'      => 600,
    'sifre_kodu_tel_sn'    => 300,
    'sifre_kodu_ip_gunluk' => 20,
    'sifre_min'            => 6,

    // Iki adimli onay / dogrulama kodu (App\Support\KodGonderimSiniri; OnayKodu::zorunlu, kasa para cikisi, randevu geldi).
    'onay_kodu' => [
        'bekleme_sn'  => 60,  // ayni anahtara yeniden gonderim beklemesi; bu surede yeni kod uretilmez
        'gunluk_kod'  => 5,   // anahtar basina gunde en cok uretilen kod
        'gunluk_hata' => 10,  // anahtar basina gunde toplam yanlis deneme (yeni kod uretilince sifirlanmaz)
        'randevu_hata' => 5,  // randevu geldi kodu: randevu basina yanlis deneme ...
        'randevu_hata_sn' => 600, // ... bu surede (RateLimiter)
    ],

    // Platform adina giden SMS (dogrulama kodu, deneme sifresi). Salon bakiyesinden dusmez. Kimlikler yalniz .env'de.
    'platform_sms' => [
        'kullanici'     => env('PLATFORM_SMS_KULLANICI'),
        'sifre'         => env('PLATFORM_SMS_SIFRE'),
        'baslik'        => env('PLATFORM_SMS_BASLIK', 'RANDVMCEPTE'),
        'zaman_asimi_sn' => 10,
    ],

    'deneme' => [
        'yazma_aktif'           => (bool) env('DENEME_V2_YAZMA_AKTIF', true),
        'gun'                   => 7,
        'uyelik_turu'           => 3,
        'sifre_uzunluk'         => 8,
        'kod_sure_sn'           => 600,
        'kod_tel_sn'            => 60,
        'kod_ip_saatlik'        => 5,
        'form_sablon_salon_id'  => (int) env('DENEME_FORM_SABLON_SALON_ID', 370),
        'hizmet_sablon_salon_id' => (int) env('GUZELLIK_SABLON_SALON_ID', 0),
        'hizmet_sablon_salon_adi' => 'SABLON - Guzellik Varsayilan Hizmetler (kullanmayin)',
    ],
];
