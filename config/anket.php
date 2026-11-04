<?php

return [
    /*
     | Memnuniyet anketi gerçek YAZMA (şablon kaydet / sil, gönderim, web gizle, Reputation ayarları) ETKİN mi?
     | Varsayılan AÇIK (3 Kas 2026 kararı: tüm v2 yazmaları açık; env ile kapatılabilir).
     | Kapalıyken yazma uçları 409 döner; okuma uçları her zaman açık.
     */
    'yazma_aktif' => (bool) env('ANKET_V2_YAZMA_AKTIF', true),
];
