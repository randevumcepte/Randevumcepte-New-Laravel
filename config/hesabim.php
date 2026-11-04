<?php

return [
    /*
     | Profil + Hesabım fatura bilgisi yazmaları ETKİN mi? Varsayılan AÇIK; kapalıyken 409.
     */
    'yazma_aktif' => (bool) env('HESABIM_V2_YAZMA_AKTIF', true),

    /*
     | Profil resimleri ESKİ monolitin public/profil_resimleri dizinine yazılır; DB'deki yol
     | eskiyle aynı biçimdedir ('/public/profil_resimleri/<ad>') — eski panel ve Flutter bulur.
     | Kök: config('ayar.eski_public_kok').
     */
    'profil_resim_alt_dizin' => 'profil_resimleri',
    'profil_resim_maks_kb'   => 8192,
    'profil_resim_yukseklik_px' => 800,

    // WhatsApp ücretsiz süresi dolunca hizmet kartında gösterilen iletişim numarası.
    'iletisim_tel' => env('HESABIM_ILETISIM_TEL', '05412948144'),
];
