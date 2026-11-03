<?php

return [
    /*
     | Seans takibi gercek YAZMA (seans ekle, durum degistir/beklemeye al, lazer cihaz
     | bilgisi kaydet) ETKIN mi? Varsayilan ACIK (3 Kas 2026: yeni panel kullanimda degil; env ile kapatilabilir). Acilmasi bilincli bir adimdir:
     | .env'de SEANS_V2_YAZMA_AKTIF=true + kontrollu test sonrasi. Kapaliyken yazma
     | uclari 409 doner (hicbir sey yazilmaz); okuma her zaman acik.
     */
    'yazma_aktif' => (bool) env('SEANS_V2_YAZMA_AKTIF', true),
];
