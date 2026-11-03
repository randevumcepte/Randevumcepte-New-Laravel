<?php

return [
    /*
     | Form Yönetimi (arşiv / form şablonu / sözleşme) gerçek YAZMA ETKİN mi?
     | Varsayılan ACIK (3 Kas 2026: yeni panel kullanimda degil; env ile kapatilabilir). Açılması bilinçli bir adımdır: .env'de FORM_V2_YAZMA_AKTIF=true
     | + kontrollü test sonrası. Kapalıyken yazma uçları 409 döner (hiçbir şey yazılmaz).
     | SMS/WhatsApp v2'den gönderilmez: v2_bildirim_kuyrugu 'form_link' türüne yazılır,
     | eski monolitin `v2:bildirim-isle` komutu gönderir.
     */
    'yazma_aktif' => (bool) env('FORM_V2_YAZMA_AKTIF', true),

    /*
     | Harici belge dosyaları ESKİ monolitin public/formlar dizinine yazılır; arsiv.uzanti
     | eskiyle aynı göreli biçimde ('public/formlar/<ad>') tutulur ki eski panel, Flutter
     | ve PDF uçları dosyayı bulmaya devam etsin.
     */
    'harici_dizin' => env('FORM_HARICI_DIZIN', '/var/www/www-root/data/www/randevumcepte/public/formlar'),
    'harici_uzantilar' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'],
    'harici_maks_kb' => 10240,
];
