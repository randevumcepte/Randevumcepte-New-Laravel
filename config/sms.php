<?php

return [
    /*
     | SMS saglayicilari — eski layout_isletmeadmin header'indaki "Kalan SMS" ile AYNI uclar.
     |   salonlar.yeni_sms=1 → VoiceTelekom (Basic auth: sms_user_name / sms_secret)
     |   aksi halde        → Efetech (Authorization: Key sms_apikey)
     */
    'voicetelekom' => [
        'kredi_url' => env('SMS_VOICETELEKOM_KREDI_URL', 'http://smsvt.voicetelekom.com:9587/user/credit'),
        'rapor_url' => env('SMS_VOICETELEKOM_RAPOR_URL', 'https://smsvt.voicetelekom.com:9588'),
        'rapor_sayfa' => 1000,      // saglayici ust siniri
        'rapor_maks_sayfa' => 20,   // 20.000 paket: sonsuz dongu sigortasi (eski kodla ayni)
    ],
    'efetech' => [
        'bakiye_url' => env('SMS_EFETECH_BAKIYE_URL', 'https://api.efetech.net.tr/v2/get/balance'),
        'rapor_url' => env('SMS_EFETECH_RAPOR_URL', 'https://api.efetech.net.tr/v2/get/report'),
    ],

    /* Saglayici erisilemezse header beklemesin (eski VoiceTelekom istemcisi 120 sn bekliyordu). */
    'zaman_asimi_sn' => (int) env('SMS_ZAMAN_ASIMI_SN', 5),

    /* Bakiye onbellegi (saniye) — eskiden HER sayfa acilisinda saglayiciya gidiliyordu. */
    'bakiye_cache_sn' => 120,

    /* SMS Yonetimi gercek YAZMA (gonderim, sablon, ayar, kara liste). Varsayilan ACIK (3 Kas 2026: tum v2
       yazmalari acik); kapaliyken yazma uclari 409. Okuma her zaman acik. */
    'yazma_aktif' => (bool) env('SMS_V2_YAZMA_AKTIF', true),

    /* Raporlar: son N gun (eski kodla ayni). VoiceTelekom'da tum paketler inmek zorunda → onbellek. */
    'rapor_gun' => 60,
    'rapor_cache_sn' => 300,
    'rapor_zaman_asimi_sn' => 60,

    /* Ayni mesaj + ayni alicilar bu sure icinde yeniden kuyruga alinmaz (cift ucretlendirme korumasi). */
    'tekrar_kilit_sn' => 120,
];
