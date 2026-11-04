<?php

return [
    /*
     | Grup dersi domaini gercek YAZMA (oturum, sablon, yayin, katilimci, otomatik plan) ETKIN mi?
     | Diger v2 domainleriyle ayni varsayilan: ACIK (yeni panel henuz canli kullanimda degil);
     | .env'de DERS_V2_YAZMA_AKTIF=false ile kapatilir, kapaliyken yazma uclari 409 doner.
     |
     | Yan etkiler v2'de uretilmez: 'ders_bildirim' outbox'i → eski `v2:bildirim-isle`
     | (DersBildirimServisi, WA-first/SMS). `ders:hatirlat` ve `ders:katilim-sor` cron'lari eski
     | monolitte kalir; ayni tablolari okudugu icin v2 kayitlarini da isler.
     */
    'yazma_aktif' => (bool) env('DERS_V2_YAZMA_AKTIF', true),
];
