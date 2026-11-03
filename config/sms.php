<?php

return [
    /*
     | SMS saglayicilari — eski layout_isletmeadmin header'indaki "Kalan SMS" ile AYNI uclar.
     |   salonlar.yeni_sms=1 → VoiceTelekom (Basic auth: sms_user_name / sms_secret)
     |   aksi halde        → Efetech (Authorization: Key sms_apikey)
     */
    'voicetelekom' => [
        'kredi_url' => env('SMS_VOICETELEKOM_KREDI_URL', 'http://smsvt.voicetelekom.com:9587/user/credit'),
    ],
    'efetech' => [
        'bakiye_url' => env('SMS_EFETECH_BAKIYE_URL', 'https://api.efetech.net.tr/v2/get/balance'),
    ],

    /* Saglayici erisilemezse header beklemesin (eski VoiceTelekom istemcisi 120 sn bekliyordu). */
    'zaman_asimi_sn' => (int) env('SMS_ZAMAN_ASIMI_SN', 5),

    /* Bakiye onbellegi (saniye) — eskiden HER sayfa acilisinda saglayiciya gidiliyordu. */
    'bakiye_cache_sn' => 120,
];
