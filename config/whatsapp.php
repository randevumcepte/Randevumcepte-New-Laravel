<?php

return [
    /*
     | WhatsApp köprüsü (whatsmeow, Go) — oturum yönetimi (başlat / durum / QR / eşleştir / çıkış).
     | Env adları eski monolitle AYNI (config/whatsapp.php); mesaj GÖNDERİMİ v2'den yapılmaz (outbox).
     | Köprü aynı sunucuda çalışır; WHATSAPP_SERVICE_TOKEN bu uygulamanın .env'inde de tanımlı olmalı.
     */
    'kopru_url'      => env('WHATSMEOW_SERVICE_URL', 'http://127.0.0.1:3002'),
    'servis_token'   => env('WHATSAPP_SERVICE_TOKEN', ''),
    'zaman_asimi_sn' => (int) env('WHATSAPP_REQUEST_TIMEOUT', 10),

    /*
     | WhatsApp Yönetimi gerçek YAZMA (bağla/çıkış, kanal, işletme linkleri, kontör talebi) ETKİN mi?
     | Varsayılan AÇIK (3 Kas 2026 kararı: tüm v2 yazmaları açık; env ile kapatılabilir). Kapalıyken
     | yazma uçları 409 döner. Okuma her zaman açık.
     */
    'yazma_aktif' => (bool) env('WHATSAPP_V2_YAZMA_AKTIF', true),
];
