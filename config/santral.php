<?php

return [
    /*
     | Santral (FreePBX) izleme API'si — eski StoreAdminController@santral_raporlari
     | ve App\SistemYonetim\DakikaHesap ile AYNI uclar.
     |   cdr_url   : freepbxapi.php  (dahililer[], did, tarih1, tarih2, limit, offset → {data,total,has_more})
     |   dakika_url: dakikaOzet.php  (did, tarih1, tarih2, dahili → {toplam_dakika, giden_cevaplanan_adet})
     */
    'cdr_url'    => env('SANTRAL_CDR_URL', 'https://santral.randevumcepte.com.tr/monitor/api/freepbxapi.php'),
    'dakika_url' => env('SANTRAL_DAKIKA_URL', 'https://santral.randevumcepte.com.tr/monitor/api/dakikaOzet.php'),
    'zaman_asimi_sn' => (int) env('SANTRAL_ZAMAN_ASIMI_SN', 20),

    /*
     | Bir tarih araligi icin santralden cekilen en fazla CDR satiri. Eski web 500
     | (API tavani) kullaniyordu; liste sunucuda siniflandirilip filtrelendigi icin
     | sayfalama bu kume uzerinde yapilir. Tavan asilirsa cevapta kesildi=true doner.
     */
    'cdr_tavan' => 500,

    /* CDR / dakika cevap onbellegi (saniye) — sayfa degistirme santrale tekrar gitmesin. */
    'cdr_cache_sn'    => 60,
    'dakika_cache_sn' => 300,

    /*
     | Santral AYAR yazma (hatirlatma aramasi, ozel karsilama menusu) ETKIN mi?
     | Varsayilan ACIK (3 Kas 2026: yeni panel kullanimda degil; env ile kapatilabilir); kapaliyken yazma uclari 409 doner (hicbir sey yazilmaz).
     */
    'yazma_aktif' => (bool) env('SANTRAL_V2_YAZMA_AKTIF', true),

    /*
     | WebRTC softphone (panel header'i) — eski public/js/santral/webphone.js ile ayni santral.
     | Ses dosyalari eski panelden (config panel.eski_panel_url) servis edilir; yollar ona goredir.
     */
    'softphone' => [
        'sip_sunucu'    => env('SOFTPHONE_SIP_SUNUCU', 'santral.randevumcepte.com.tr'),
        'wss_url'       => env('SOFTPHONE_WSS_URL', 'wss://santral.randevumcepte.com.tr:8089/ws'),
        'zil_yolu'      => '/public/telefon-ses/phone_incoming.mp3',
        'geri_zil_yolu' => '/public/telefon-ses/phone-outgoing.mp3',
    ],
];
