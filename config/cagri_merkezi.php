<?php

return [
    /*
     | Çağrı Merkezi v2 YAZMA (görüşme sonucu, arama randevusu, liste yönetimi, telefonda satış,
     | anket) ETKİN mi? Varsayılan AÇIK (3 Kas 2026 kararı: tüm *_V2_YAZMA_AKTIF açık);
     | env ile kapatılabilir — kapalıyken yazma uçları 409 döner, hiçbir şey yazılmaz.
     | Arama BAŞLATMA (santral originate) yazma değildir; bu bayraktan bağımsızdır.
     */
    'yazma_aktif' => (bool) env('CAGRI_MERKEZI_V2_YAZMA_AKTIF', true),

    /*
     | Santral AMI (click-to-call originate) — eski StoreAdminController::cagriMerkeziOriginate
     | ile aynı sunucu / kullanıcı. Agent-first: önce personelin dahilisi (Bria) çalar, açınca
     | müşteri salonun outbound peer'inden aranır; numara istemciye GİTMEZ.
     | Kimlik bilgileri YALNIZ sunucu .env'inden (SANTRAL_AMI_KULLANICI / SANTRAL_AMI_SECRET);
     | tanımsızsa arama başlatma anlaşılır hata döner, başka hiçbir şey etkilenmez.
     */
    'ami' => [
        'host'        => env('SANTRAL_AMI_HOST'),
        'port'        => (int) env('SANTRAL_AMI_PORT', 5038),
        'kullanici'   => env('SANTRAL_AMI_KULLANICI'),
        'secret'      => env('SANTRAL_AMI_SECRET'),
        'zaman_asimi' => (int) env('SANTRAL_AMI_ZAMAN_ASIMI_SN', 10),
        'cevap_sn'    => 60,          // Dial zaman aşımı (müşteri bacağı)
        'hesap'       => 'cmerkezi',  // CDR accountcode: santral raporu bu çağrıları ayıklar
    ],

    /* Ses kaydı host'ları: görüşme notu kayıtları (eski sesKaydiUrl) ve Çağrı Kayıtları (eski voicerecords). */
    'ses_host'     => env('CAGRI_SES_HOST', 'https://santral.randevumcepte.com.tr'),
    'cdr_ses_host' => env('CAGRI_CDR_SES_HOST', 'https://voicerecords.randevumcepte.com.tr'),

    /* Pencereler (gün) — eski kodla aynı. */
    'gecmis_ses_gun'      => 5,    // müşteri geçmişi ses adayı penceresi
    'gecmis_ses_esik_gun' => 2,    // bu günden eski notların kaydı yeniden atanmaz
    'tum_ses_gun'         => 120,  // "Tüm Ses Kayıtları" penceresi (eski musteri_ses_kayitlari)
    'kayitlar_gun'        => 60,   // Çağrı Kayıtları varsayılan aralığı
    'kayitlar_tavan'      => 800,  // Çağrı Kayıtları en fazla satır

    'manuel_liste_baslik' => 'Arama Randevuları (Manuel)',
];
