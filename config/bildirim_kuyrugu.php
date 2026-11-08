<?php

use App\Services\BildirimKuyrugu\Isleyici;

return [
    /*
     | v2:bildirim-isle acik mi? Varsayilan KAPALI: siz acmadan bu app'ten hic mesaj gitmez. Kapaliyken
     | zamanlayici komutu hic baslatmaz, elle cagrilinca uyarip cikar; kuyruk satirlari dokunulmadan bekler.
     */
    'isleyici_aktif' => env('BILDIRIM_ISLEYICI_AKTIF', false),

    /*
     | Toplu turlerin job kuyrugu; yeni app'in kendi worker'i dinler. 'hatirlatmalar' olamaz: jobs tablosu ortak, eski monolitin
     | worker'i o kuyrugu dinler, yeni is siniflarini taniyamaz ve tries=1 oldugu icin mesaj kaybolur.
     */
    'kuyruk_adi' => env('BILDIRIM_KUYRUK_ADI', 'v2_bildirim'),

    /* Firebase servis hesabi JSON'larinin dizini. Icerik hic loglanmaz; dosyalar git'e girmez. */
    'firebase_anahtar_dizini' => env('FIREBASE_ANAHTAR_DIZINI', storage_path('app/firebase')),

    /*
     | Salon-bagimsiz sistem SMS'i: eski Controller::sms_gonder('', ...) yolu. Eski kodda anahtar kaynakta sabittir;
     | buraya tasinmadi, sunucu .env'inde tanimlanmali. Bos ise Efetech anahtarsiz cagrilir.
     */
    'sistem_sms' => [
        'apikey' => env('SMS_EFETECH_SISTEM_APIKEY', ''),
        'baslik' => env('SMS_EFETECH_SISTEM_BASLIK', 'RANDVMCEPTE'),
    ],

    /*
     | Bayrak acikken de eski monolitte kalan turler: satir durum 0 yazilir, eskisi gibi oradan gider. Google Takvim
     | entegrasyonu en sona birakildi (kullanici 2026-11-08); tasininca buradan cikarilir ve GOOGLE_CLIENT_* tanimlanir.
     */
    'eski_isleyicide_kalan' => ['google_takvim'],

    /* Google Takvim belirteci tazeleme (eski GoogleCalendarService env'leri). Baglanti tablolari ortak; OAuth akisi eski panelde. */
    'google_takvim' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    /*
     | saat_boslugu_kampanya: v1'in push ve dashboard onbellegi yan etkileri. Eski outbox isleyicisi bu turu bilmez, satir hataya
     | duser; yeni isleyici v1 davranisini geri getirir. Kapatilirsa satir eskisi gibi hataya duser.
     */
    'saat_boslugu_push_aktif' => env('BILDIRIM_SAAT_BOSLUGU_PUSH_AKTIF', true),

    /*
     | Tur → isleyici sinifi. Yeni tur eklenince buraya ve sinifina yazilir. Haritada olmayan tur eskideki
     | gibi hata olur (3 deneme, sonra durum=2). sms_toplu burada eski satirlar icindir (durum=0); yeni kayitlar
     | durum=4 ile sms:kuyruk-isle tarafindan islenir.
     */
    'harita' => [
        // Randevu
        'randevu_iptal'   => Isleyici\RandevuIptalIsleyici::class,
        'randevu_onay'    => Isleyici\RandevuOnayIsleyici::class,
        'randevu_kayit'   => Isleyici\RandevuKayitIsleyici::class,
        'randevu_tasindi' => Isleyici\RandevuTasindiIsleyici::class,
        'dogrulama_kodu'  => Isleyici\DogrulamaKoduIsleyici::class,
        'kvkk_kodu'       => Isleyici\KvkkKoduIsleyici::class,
        'seans_kullanim'  => Isleyici\SeansKullanimIsleyici::class,
        'ongorusme_kayit' => Isleyici\OngorusmeKayitIsleyici::class,

        // Kisi
        'personel_sifre'      => Isleyici\PersonelSifreIsleyici::class,
        'yetki_degisti'       => Isleyici\YetkiDegistiIsleyici::class,
        'personel_pasif'      => Isleyici\PersonelPasifIsleyici::class,
        'sifre_degisti'       => Isleyici\SifreDegistiIsleyici::class,
        'musteri_kayit'       => Isleyici\MusteriKayitIsleyici::class,
        'kara_liste_bildirim' => Isleyici\KaraListeBildirimIsleyici::class,
        'ders_bildirim'       => Isleyici\DersBildirimIsleyici::class,
        'whatsapp_mesaj'      => Isleyici\WhatsappMesajIsleyici::class,
        'sistem_bildirim'     => Isleyici\SistemBildirimIsleyici::class,
        'sms'                 => Isleyici\SmsIsleyici::class,
        'form_link'           => Isleyici\FormLinkIsleyici::class,

        // Toplu
        'bildirim_reklam_push'  => Isleyici\BildirimReklamPushIsleyici::class,
        'cark_duyuru'           => Isleyici\CarkDuyuruIsleyici::class,
        'sms_toplu'             => Isleyici\SmsTopluIsleyici::class,
        'kampanya_olustur'      => Isleyici\KampanyaOlusturIsleyici::class,
        'anket_link'            => Isleyici\AnketLinkIsleyici::class,
        'anket_gonder'          => Isleyici\AnketGonderIsleyici::class,
        'google_takvim'         => Isleyici\GoogleTakvimIsleyici::class,
        'saat_boslugu_kampanya' => Isleyici\SaatBosluguKampanyaIsleyici::class,
        'musteri_resim_push'    => Isleyici\MusteriResimPushIsleyici::class, // MusteriResimServisi, yalniz bayrak acikken yazar
    ],
];
