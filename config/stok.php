<?php

return [
    /*
     | Stok domaini gercek YAZMA (urun/kategori/depo/tedarikci/recete kaydet-sil,
     | manuel hareket, alis girisi, transfer, sayim, hizli satis) ETKIN mi?
     | Varsayilan ACIK (3 Kas 2026: yeni panel kullanimda degil; env ile kapatilabilir). Acilmasi bilincli bir adimdir: .env'de
     | STOK_V2_YAZMA_AKTIF=true + kontrollu test sonrasi. Kapaliyken yazma uclari
     | 409 doner (hicbir sey yazilmaz). Hizli satista pesin tahsilat ayrica
     | tahsilat.yazma_aktif ister (finansal kayit).
     */
    'yazma_aktif' => (bool) env('STOK_V2_YAZMA_AKTIF', true),
];
