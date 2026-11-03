<?php

return [
    /*
     | Seans takibi gercek YAZMA (seans ekle, durum degistir/beklemeye al, lazer cihaz
     | bilgisi kaydet) ETKIN mi? Varsayilan KAPALI. Acilmasi bilincli bir adimdir:
     | .env'de SEANS_V2_YAZMA_AKTIF=true + kontrollu test sonrasi. Kapaliyken yazma
     | uclari 409 doner (hicbir sey yazilmaz); okuma her zaman acik.
     */
    'yazma_aktif' => (bool) env('SEANS_V2_YAZMA_AKTIF', false),
];
