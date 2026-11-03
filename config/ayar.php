<?php

return [
    /*
     | Ayarlar (isletme bilgileri, logo/galeri, calisma saatleri, randevu ayarlari,
     | online randevu saatleri, musteri indirimleri, tema) gercek YAZMA ETKIN mi?
     | Varsayilan ACIK (3 Kas 2026: yeni panel kullanimda degil; env ile kapatilabilir). Acilmasi bilincli bir adimdir: .env'de AYAR_V2_YAZMA_AKTIF=true
     | + kontrollu test sonrasi. Kapaliyken yazma uclari 409 doner (hicbir sey yazilmaz).
     */
    'yazma_aktif' => (bool) env('AYAR_V2_YAZMA_AKTIF', true),

    /*
     | Logo + galeri ESKI monolitin public/salon_gorselleri dizinine yazilir; DB'deki yol
     | eskiyle ayni bicimde tutulur (logo: 'public/salon_gorselleri/<ad>', galeri:
     | '/public/salon_gorselleri/<ad>') ki mini site, eski panel ve Flutter bulmaya devam etsin.
     | eski_public_kok: silmede goreli yolun cozulecegi kok (monolitin public/ dizini).
     */
    'gorsel_dizin'    => env('AYAR_GORSEL_DIZIN', '/var/www/www-root/data/www/randevumcepte/public/salon_gorselleri'),
    'eski_public_kok' => env('AYAR_ESKI_PUBLIC_KOK', '/var/www/www-root/data/www/randevumcepte/public'),
    'gorsel_maks_kb'  => 8192,
    'galeri_yukseklik_px' => 720,
];
