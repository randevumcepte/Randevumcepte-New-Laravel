<?php

return [
    /*
     | Musteri tarafi (mini site + web musteri paneli + Flutter musteri uygulamasi).
     | Yazma kapisi ayri degil: randevu icin RandevuYazma (config/randevu.php) kullanilir.
     */

    // Musteriye sunulan en ileri tarih (Flutter 180 gun listeler).
    'maks_gun' => (int) env('ONLINE_RANDEVU_MAKS_GUN', 180),

    // v2'nin urettigi musteri token'i eski Passport ile ayni personal access client'a baglanir.
    'token_client_id' => (int) env('MUSTERI_TOKEN_CLIENT_ID', 22),
    'token_gun'       => (int) env('MUSTERI_TOKEN_GUN', 365),

    // Kotuye kullanim sinirlari (eski randevuonaylaDirekt / sifregonder ile ayni degerler).
    'kayit_ip_saatlik'    => 5,
    'kayit_tel_gunluk'    => 3,
    'kayit_min_saniye'    => 3,
    'giris_deneme'        => 10,
    'giris_deneme_sn'     => 600,
    'sifre_kodu_tel_sn'   => 300,
    'sifre_kodu_ip_gunluk'=> 20,
];
