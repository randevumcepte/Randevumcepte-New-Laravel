<?php

return [
    /*
     | Uygulama İndirme Afişi (eski /isletmeyonetim/uygulama-afisi) — salt okuma ekranı.
     |
     | kaynak_dizin : afiş arka planı (afis-bg.png, 1588x2246 = A4 @ ~192 dpi) + Poppins fontları.
     |                Web'e açık DEĞİL (resources/); görsel yalnız /api/v2/uygulama-afisi/* ile çıkar.
     | indir_taban  : QR hedefi + paylaşılan link. /indir/{salon} akıllı yönlendirmesi ESKİ
     |                monolitte yaşar (herkese açık) — QR'lar basılı afişlerde olduğundan bu
     |                adres DEĞİŞMEMELİ (varsayılan: panel.eski_panel_url).
     | onbellek_dk  : üretilen PNG/JPEG önbelleği. Anahtar içerik parmak izidir (ad, logo +
     |                mtime, link) — salon adı / logo değişince kendiliğinden yenilenir.
     | jpeg_kalite  : PDF'e gömülen görselin kalitesi (baskı için yüksek tutulur).
     */
    'kaynak_dizin' => env('AFIS_KAYNAK_DIZIN', resource_path('afis')),
    'indir_taban'  => env('AFIS_INDIR_TABAN', env('ESKI_PANEL_URL', 'https://app.randevumcepte.com.tr')),
    'onbellek_dk'  => (int) env('AFIS_ONBELLEK_DK', 1440),
    'jpeg_kalite'  => 92,
];
