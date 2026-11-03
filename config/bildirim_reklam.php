<?php

return [
    /*
     | Bildirim reklamı gerçek YAZMA (kaydet / durum / sil / görsel / push gönder) ETKİN mi?
     | Varsayılan AÇIK (3 Kas 2026 kararı: tüm v2 yazmaları açık; env ile kapatılabilir).
     | Kapalıyken yazma uçları 409 döner. Okuma uçları ve müşteri uygulaması uçları her zaman açık;
     | müşterinin kupon kapması müşteri eylemidir, bu bayrağa bağlı değildir.
     */
    'yazma_aktif' => (bool) env('BILDIRIM_REKLAM_V2_YAZMA_AKTIF', true),

    /*
     | Görseller ESKİ monolitin public/reklam_gorselleri dizinine yazılır; DB yolu eskiyle aynı
     | biçimde ('/public/reklam_gorselleri/<ad>') ki eski panel, push job'u ve Flutter bulmaya devam etsin.
     */
    'gorsel_dizin'   => env('BILDIRIM_REKLAM_GORSEL_DIZIN', '/var/www/www-root/data/www/randevumcepte/public/reklam_gorselleri'),
    'gorsel_yol'     => '/public/reklam_gorselleri/',
    'gorsel_maks_kb' => 8192,
    'gorsel_uzantilari' => ['jpg', 'jpeg', 'png', 'webp'],

    /* Form ipucu: açılış tam ekran popup için önerilen görsel (Instagram Story). */
    'gorsel_onerisi' => '9:16 (1080×1920)',
];
