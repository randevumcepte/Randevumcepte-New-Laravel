<?php

return [
    /*
     | Eski monolit paneli (strangler geçişinde v2 menü linkleri buraya gider).
     | v2 ekranı eski panelle PARALEL kullanılır; menü/şube linkleri app.* host'una
     | mutlak olarak üretilir.
     */
    'eski_panel_url' => env('ESKI_PANEL_URL', 'https://app.randevumcepte.com.tr'),
];
