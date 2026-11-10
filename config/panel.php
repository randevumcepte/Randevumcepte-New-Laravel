<?php

return [
    /*
     | Eski monolit paneli (strangler geçişinde v2 menü linkleri buraya gider).
     | v2 ekranı eski panelle PARALEL kullanılır; menü/şube linkleri app.* host'una
     | mutlak olarak üretilir.
     */
    'eski_panel_url' => env('ESKI_PANEL_URL', 'https://app.randevumcepte.com.tr'),

    /*
     | Randevu modülü (App\Services\Panel\RandevuModulu) — eski panelde HTTP_HOST ==
     | 'randevu.randevumcepte.com.tr' koşullarının tek kaynağı. Bu host'lardan girilen işletme
     | paneli yalnız randevu odaklı çalışır: satış, tahsilat, raporlama, çağrı merkezi ve diğer
     | yönetim bölümleri kapalıdır. Mobil uygulama ve app.* host'u etkilenmez.
     |
     |  hostlar     virgüllü liste (RANDEVU_MODULU_HOSTLAR); boş bırakılırsa modül hiçbir yerde açılmaz
     |  ek_sayfalar menüde/kısayolda olmayan ama açık kalan v2 sayfaları (Str::is, baştaki / yok).
     |              Menüde 'randevu_modulu' => true işaretli öğelerin sayfaları ayrıca açıktır.
     |  kapali_api  işletme API uçlarından kapananlar (api/v2/ sonrası, Str::is) → 403 'randevu_modulu'
     */
    'randevu_modulu' => [
        'hostlar' => array_values(array_filter(array_map(
            fn ($h) => strtolower(trim($h)),
            explode(',', (string) env('RANDEVU_MODULU_HOSTLAR', 'randevu.randevumcepte.com.tr')),
        ))),

        'ek_sayfalar' => [
            'v2/giris',
            'v2/sifremi-unuttum',
            'v2/musteri/*',   // müşteri kartı (Müşteriler listesinden açılır)
        ],

        'kapali_api' => [
            // Satış & tahsilat
            'satislar', 'satislar/*',
            'adisyon', 'adisyon/*',
            'kalem/*',
            'tahsilat', 'tahsilat/*',
            'alacak', 'alacak/*',
            'randevu/*/tahsilat',
            'ongorusme/*/satis',
            'mobil-para/*',
            // Raporlama
            'ozet', 'ozet/*',
            'rapor/*',
            'kasa/*',
            'masraf', 'masraf/*',
            'prim/*',
            // Çağrı merkezi & santral
            'cagri-merkezi', 'cagri-merkezi/*',
            'santral', 'santral/*',
            'softphone', 'softphone/*',
        ],
    ],
];
