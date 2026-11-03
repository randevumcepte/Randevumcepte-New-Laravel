<?php

return [
    /*
     | Ön görüşme domaini gerçek YAZMA (ekle / düzenle / satış yapıldı / yapılmadı) ETKİN mi?
     | Varsayılan AÇIK (3 Kas 2026 kararı: tüm v2 yazmaları açık; env ile kapatılabilir).
     | Kapalıyken yazma uçları 409 döner (hiçbir şey yazılmaz). Okuma uçları her zaman açık.
     */
    'yazma_aktif' => (bool) env('ONGORUSME_V2_YAZMA_AKTIF', true),

    /*
     | Ön görüşme randevusu: takvimde varsayılan süre ve saat seçici aralığı (eski modal:
     | 07:00–23:45, 15 dk adım; randevu 60 dk). Web + Flutter seçenekleri form-verisi'nden okur.
     */
    'sure_dk'      => 60,
    'saat_araligi' => ['baslangic' => '07:00', 'bitis' => '23:45', 'adim_dk' => 15],

    /*
     | Ön görüşme randevusunun randevu_hizmetler.hizmet_id değeri — gerçek hizmet DEĞİL,
     | takvimde "Ön Görüşme" bloğu (mobil v1 ile aynı: 1). Asıl neden on_gorusmeler'de tutulur.
     */
    'randevu_hizmet_id' => 1,
];
