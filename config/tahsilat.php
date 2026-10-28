<?php

return [
    /*
     | Gerçek finansal YAZMA (POST /api/v2/tahsilat, dry_run=false) ETKİN mi?
     | Varsayılan KAPALI. Açılması bilinçli bir adımdır: .env'de
     | TAHSILAT_V2_YAZMA_AKTIF=true + kontrollü test sonrası. Kapalıyken
     | dry_run=false istekleri 409 döner (hiçbir şey yazılmaz).
     */
    'yazma_aktif' => (bool) env('TAHSILAT_V2_YAZMA_AKTIF', false),
];
