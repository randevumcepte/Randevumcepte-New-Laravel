<?php

return [
    /*
     | Randevu durum YAZMA (geldi/gelmedi/isaret-kaldir/iptal/onayla, dry_run=false)
     | ETKIN mi? Varsayilan KAPALI. Kapaliyken yalnizca DRY-RUN (plan) doner,
     | dry_run=false istekleri 409 alir (hicbir sey yazilmaz).
     |
     | DIKKAT: Faz 2b (bildirim koprusu: musteri/personel SMS-WA, FCM push,
     | uygulama-ici bildirim, dogrulama/KVKK kodu GONDERIMI, seans kullanim push'u)
     | bitmeden ACMAYIN — v2 bu yan etkileri henuz uretmez.
     | Acmak: .env RANDEVU_V2_YAZMA_AKTIF=true
     */
    'yazma_aktif' => (bool) env('RANDEVU_V2_YAZMA_AKTIF', false),
];
