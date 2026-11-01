<?php

return [
    /*
     | Randevu v2 YAZMA (durum, telafi, saat kapama, surukle-birak, olustur/guncelle;
     | dry_run=false) ETKIN mi? Varsayilan KAPALI → yalnizca DRY-RUN (plan) doner,
     | dry_run=false istekleri 409 alir.
     |
     | yazma_aktif      : TUM salonlarda acar (genel acilis).
     | yazma_salonlari  : yalnizca listedeki salonlarda acar (pilot). CSV: "20,236".
     |
     | Yan etkiler (SMS/WA, FCM, uygulama-ici bildirim, dogrulama/KVKK kodu, seans
     | push'u, Google Takvim) 'v2_bildirim_kuyrugu'na yazilir; eski monolitin
     | `v2:bildirim-isle` komutu gonderir. ACMADAN ONCE kontrol listesi:
     | docs/strangler-randevu-yol-haritasi.md
     */
    'yazma_aktif'     => (bool) env('RANDEVU_V2_YAZMA_AKTIF', false),
    'yazma_salonlari' => array_values(array_filter(array_map('intval', explode(',', (string) env('RANDEVU_V2_YAZMA_SALONLARI', ''))))),
];
