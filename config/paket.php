<?php

return [
    /*
     | Paket domaini gercek YAZMA (paket tanimi ekle/guncelle/sil + Paket Yonetimi
     | "Satis Yap") ETKIN mi? Varsayilan ACIK (3 Kas 2026: yeni panel kullanimda degil; env ile kapatilabilir). Acilmasi bilincli bir adimdir:
     | .env'de PAKET_V2_YAZMA_AKTIF=true + kontrollu test sonrasi. Kapaliyken yazma
     | uclari 409 doner (hicbir sey yazilmaz). Okuma uclari her zaman aciktir.
     */
    'yazma_aktif' => (bool) env('PAKET_V2_YAZMA_AKTIF', true),
];
