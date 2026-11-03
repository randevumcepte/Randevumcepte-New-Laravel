<?php

return [
    /*
     | Kasa v2 YAZMA (para ekle / cek, kasadan gelir silme, masraf ekle/duzenle/sil) ETKIN mi?
     | Varsayilan KAPALI. Kapaliyken yazma uclari 409 doner (hicbir sey yazilmaz); okuma
     | (kasa raporu, gun sonu, aylik) her zaman acik.
     | Acmak: .env'de KASA_V2_YAZMA_AKTIF=true (sunucuda git pull sonrasi config:clear).
     */
    'yazma_aktif' => (bool) env('KASA_V2_YAZMA_AKTIF', false),
];
