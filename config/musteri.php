<?php

return [
    /*
     | Musteri domaini gercek YAZMA (ekle/duzenle, sil, kara liste, saglik, olcum)
     | ETKIN mi? Varsayilan KAPALI. Acilmasi bilincli bir adimdir:
     | .env'de MUSTERI_V2_YAZMA_AKTIF=true + kontrollu test sonrasi.
     | Kapaliyken yazma uclari 409 doner (hicbir sey yazilmaz).
     |
     | Yan etkiler (yeni musteri SMS'i + sifre, kara liste bildirimi) v2'de
     | uretilmez; v2_bildirim_kuyrugu → eski `v2:bildirim-isle` mevcut kodla gonderir
     | (turler: musteri_kayit, kara_liste_bildirim).
     */
    'yazma_aktif' => (bool) env('MUSTERI_V2_YAZMA_AKTIF', false),
];
