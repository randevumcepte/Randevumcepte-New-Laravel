<?php

return [
    /*
     | Bildirim gerçek YAZMA (okundu / sil / cihaz kaydı) ETKİN mi? Varsayılan AÇIK; kapalıyken panel
     | uçları 409 döner. Müşterinin kendi kutusundaki işlemler bu bayrağa bağlı değildir.
     */
    'yazma_aktif' => (bool) env('BILDIRIM_V2_YAZMA_AKTIF', true),

    /*
     | Tarayıcı push'u için Firebase Web SDK anahtarları (gizli değildir; eski config/firebase_web.php ile
     | AYNI proje olmalı, gönderim eski monolitten web projesi hesabıyla yapılır). Boşsa web push kapalıdır.
     */
    'web_push' => [
        'apiKey'            => env('FIREBASE_API_KEY'),
        'authDomain'        => env('FIREBASE_AUTH_DOMAIN'),
        'projectId'         => env('FIREBASE_PROJECT_ID'),
        'storageBucket'     => env('FIREBASE_STORAGE_BUCKET'),
        'messagingSenderId' => env('FIREBASE_MESSAGING_SENDER_ID'),
        'appId'             => env('FIREBASE_APP_ID'),
        'vapidKey'          => env('FIREBASE_VAPID_KEY'),
    ],
];
