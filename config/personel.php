<?php

return [
    /*
     | Personel domaini gercek YAZMA (ekle/duzenle, durum, siralama, takvim,
     | saat, arsivle, yetki kaydet) ETKIN mi? Varsayilan KAPALI.
     | Acilmasi bilincli bir adimdir: .env'de PERSONEL_V2_YAZMA_AKTIF=true +
     | kontrollu test sonrasi. Kapaliyken yazma uclari 409 doner (hicbir sey yazilmaz).
     |
     | NOT (Faz 2b): yetki-kaydet ve sifre/olusturma mesaji, eski sistemdeki
     | force_logout FCM push'u ve WhatsApp/SMS koprusu bu projede kurulana kadar
     | bu flag acilsa bile ayrica dikkat ister (bkz. YetkiYonetimServisi::kaydet).
     */
    'yazma_aktif' => (bool) env('PERSONEL_V2_YAZMA_AKTIF', false),
];
