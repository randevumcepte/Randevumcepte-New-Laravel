<?php

return [
    /*
     | E-Asistan v2 YAZMA (gorev iptal, ayar kaydet) ETKIN mi? Varsayilan ACIK (3 Kas 2026: yeni panel kullanimda degil; env ile kapatilabilir).
     | Kapaliyken yazma uclari 409 doner (hicbir sey yazilmaz); okuma her zaman acik.
     | Acmak: .env'de ASISTAN_V2_YAZMA_AKTIF=true (sunucuda git pull sonrasi config:clear).
     */
    'yazma_aktif' => (bool) env('ASISTAN_V2_YAZMA_AKTIF', true),
];
