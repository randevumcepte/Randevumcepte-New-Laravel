<?php

return [
    /*
     | Sistem yonetimi paneli (eski /sistemyonetim/v2). docs/strangler-sistem-yonetim.md
     */
    'yazma_aktif' => (bool) env('SISTEM_V2_YAZMA_AKTIF', true),

    // Yonetici oturumu; isletme token'lari 1 yil, buradaki yetki cok daha genis.
    'token_saat'         => (int) env('SISTEM_TOKEN_SAAT', 12),
    'impersonation_saat' => 10,   // eski imp_iy cerezi 600 dk

    'giris_deneme'    => 5,
    'giris_deneme_sn' => 60,

    // Eski monolitin storage'i: bu JSON ayarlari iki uygulamada ORTAK (eski cron'lar da okur).
    'wa_ayar_dosyasi' => env('SISTEM_WA_AYAR_DOSYASI', '/var/www/www-root/data/www/randevumcepte/storage/app/sistem_wa.json'),
    'ai_ayar_dosyasi' => env('SISTEM_AI_AYAR_DOSYASI', '/var/www/www-root/data/www/randevumcepte/storage/app/ai_kredi_ayar.json'),
];
