<?php

return [
    /*
     | Reklam Yönetimi (kampanya) gerçek YAZMA (kampanya ekle/düzenle/sil, katılımcı işlemleri,
     | senaryo kaydet/sil) ETKİN mi? Varsayılan AÇIK (3 Kas 2026 kararı: tüm v2 yazmaları açık;
     | env ile kapatılabilir). Kapalıyken yazma uçları 409 döner. Okuma / önizleme her zaman açık.
     */
    'yazma_aktif' => (bool) env('KAMPANYA_V2_YAZMA_AKTIF', true),
];
