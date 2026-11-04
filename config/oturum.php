<?php

return [
    /*
     | Giris, sifre sifirlama ve ucretsiz deneme (web + Flutter tek uc). docs/strangler-oturum.md
     */

    // Ana uygulama: tum markalarin yetkilileri girer, yalniz uyelik_turu 3 disi salonlar gosterilir (v1 ile ayni).
    'ana_uygulama_bundle' => 'com.randevumcepte.randevumcepte',

    // Apple/Google inceleme hesaplari: sifreleri sifirlanamaz, SMS gitmez.
    'korunan_hesaplar' => ['isletme' => [3], 'musteri' => [46120]],

    'giris_deneme'         => 10,
    'giris_deneme_sn'      => 600,
    'sifre_kodu_tel_sn'    => 300,
    'sifre_kodu_ip_gunluk' => 20,
    'sifre_min'            => 6,

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
