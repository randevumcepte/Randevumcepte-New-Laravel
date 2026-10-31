<?php

return [
    /*
     | Randevu durum YAZMA (geldi/gelmedi/isaret-kaldir/iptal/onayla, dry_run=false)
     | ETKIN mi? Varsayilan KAPALI. Kapaliyken yalnizca DRY-RUN (plan) doner,
     | dry_run=false istekleri 409 alir (hicbir sey yazilmaz).
     |
     | Yan etkiler (SMS/WA, FCM, uygulama-ici bildirim, dogrulama/KVKK kodu, seans
     | push'u) 'v2_bildirim_kuyrugu'na yazilir; eski monolitin `v2:bildirim-isle`
     | komutu gonderir. ACMADAN ONCE: eski klasorde migration uygulanmis ve
     | `v2:bildirim-isle` cron'u calisiyor olmali (bkz. docs/strangler-randevu).
     | Acmak: .env RANDEVU_V2_YAZMA_AKTIF=true
     */
    'yazma_aktif' => (bool) env('RANDEVU_V2_YAZMA_AKTIF', false),
];
