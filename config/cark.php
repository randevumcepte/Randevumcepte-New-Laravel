<?php

return [
    /*
     | Çarkıfelek + sadakat (puan ödülü / kazanım kuralı) gerçek YAZMA etkin mi?
     | Varsayılan AÇIK (3 Kas 2026 kararı: tüm v2 yazmaları açık; env ile kapatılabilir).
     | Kapalıyken yazma uçları 409 döner. Okuma uçları her zaman açık.
     */
    'yazma_aktif' => (bool) env('CARK_V2_YAZMA_AKTIF', true),

    /* Dilim sayısı sınırı (eski web: min 6 · maks 12; mobil min 6). */
    'dilim_min' => 6,
    'dilim_max' => 12,

    /* Dilim renk paleti (eski web COLORS) — yeni dilim bu sırayla renk alır. Web + Flutter aynı liste. */
    'renkler' => [
        '#FF6B6B', '#FF8E53', '#FFC107', '#51CF66', '#339AF0', '#CC5DE8', '#F06595', '#74C0FC', '#63E6BE', '#FFD43B',
        '#FF922B', '#20C997', '#4DABF7', '#DA77F2', '#F783AC', '#E64980', '#7950F2', '#4C6EF5', '#228BE6', '#099268',
    ],

    /* Hiç kurulmamış salonda editörün açılış dilimleri (eski web varsayılanı; ilki kazanan). */
    'varsayilan_dilimler' => [
        ['name' => '100 Puan',    'tip' => 'puan',            'deger' => 100,  'indirim_tipi' => 'yuzde'],
        ['name' => '%20 Hizmet',  'tip' => 'hizmet_indirimi', 'deger' => 20,   'indirim_tipi' => 'yuzde'],
        ['name' => '%10 Ürün',    'tip' => 'urun_indirimi',   'deger' => 10,   'indirim_tipi' => 'yuzde'],
        ['name' => '50 Puan',     'tip' => 'puan',            'deger' => 50,   'indirim_tipi' => 'yuzde'],
        ['name' => 'Tekrar Dene', 'tip' => 'tekrar_dene',     'deger' => null, 'indirim_tipi' => 'yuzde'],
        ['name' => 'Boş',         'tip' => 'bos',             'deger' => null, 'indirim_tipi' => 'yuzde'],
    ],

    /*
     | Salon sahibi kural girmediğinde müşteriye gösterilen KORUYUCU kullanım kuralları
     | (eski App\CarkifelekSistemi::VARSAYILAN_KURALLAR ile birebir — "çekiliş/şans oyunu"
     | değil "sadakat/hediye kampanyası" kurgusu). Müşteri çark ekranı eski monolitte;
     | metin değişirse ikisi birlikte güncellenir.
     */
    'varsayilan_kurallar' => "• Bu uygulama bir piyango, çekiliş veya şans oyunu DEĞİLDİR; salonumuzun müşterilerine yönelik bir sadakat ve hediye kampanyasıdır. Katılım ücretsizdir ve isteğe bağlıdır.\n"
        ."• Verilen hediye (puan, indirim veya armağan) şans/talih unsuruna dayanmaz; kampanya koşullarını sağlayan müşterilerimize sunulan bir teşekkür hediyesidir.\n"
        ."• Hediyeler yalnızca salonumuzun kendi hizmet/ürünlerinde geçerlidir; nakit değildir, nakde çevrilemez, başkasına devredilemez ve satılamaz.\n"
        ."• Her müşteri çarkı günde en fazla 1 kez çevirebilir; kazanılan hediye yalnızca salonumuzdan alınan hizmet/ürün sırasında kullanılabilir.\n"
        ."• Hediye/indirimler yalnızca belirtilen geçerlilik süresi içinde ve tek seferde kullanılır; süresi geçenler geçersiz olur.\n"
        ."• Hediye/indirimler başka kampanya, indirim veya promosyonlarla birleştirilemez.\n"
        ."• Yararlanma, randevu/hizmet sırasında ilgili hediye kodunun salonumuza iletilmesiyle yapılır.\n"
        ."• Teknik hata, sistem arızası veya kötüye kullanım tespit edilen işlemler geçersiz sayılır.\n"
        ."• Salonumuz; uygunluk, stok veya işletme koşullarına göre hediyeyi eşdeğeriyle değiştirme ya da kampanyayı durdurma hakkını saklı tutar.\n"
        .'• Kampanya koşulları önceden haber verilmeksizin güncellenebilir.',

    /*
     | Günlük hatırlatma push'ları (eski cark_hatirlatma_ayarlari; gönderimi eski monolitin
     | `cark:hatirlatma-gonder` cron'u yapar). Kayıt yoksa bu varsayılanlar gösterilir.
     */
    'hatirlatma' => [
        'slotlar' => [
            1 => ['baslik' => 'Sabah Hatırlatması', 'altyazi' => 'Güne hediyeyle başla', 'ikon' => '🎡', 'saat' => '10:00', 'mesaj' => '🎡 Bugün çark hakkınız var, hediyeler sizi bekliyor!'],
            2 => ['baslik' => 'Öğleden Sonra',      'altyazi' => 'Çark hâlâ açık',       'ikon' => '⏰', 'saat' => '15:00', 'mesaj' => '⏰ Çark hakkınız hâlâ duruyor — son birkaç saat!'],
            3 => ['baslik' => 'Akşam Hatırlatması', 'altyazi' => 'Bugünün son şansı',    'ikon' => '🚨', 'saat' => '20:00', 'mesaj' => '🚨 Son 4 saat! Çarkı çevirmeyi unutmayın'],
        ],
        'mesaj_max' => 200,
    ],

    /* Duyuru push'u (eski mobil carkBildirimGonder) boş gelirse kullanılan metinler. {ad} {salon_adi} yer tutucu. */
    'duyuru' => [
        'mesaj' => "Çark-ı Felek'i çevir, sürpriz ödülü kap! Bugün şansını dene 🎁",
    ],

    /* Puan ödülü merdiveni boşken "Örnekleri Yükle" ile eklenen 6 basamak (eski puanOrneklerEkle). */
    'puan_odul_ornekleri' => [
        ['puan_esigi' => 100,  'baslik' => '%10 Hizmet İndirimi',  'aciklama' => 'Bir sonraki hizmet alımınızda %10 indirim kazanın.', 'tip' => 'hizmet_indirimi', 'deger' => 10],
        ['puan_esigi' => 250,  'baslik' => '%20 Hizmet İndirimi',  'aciklama' => 'Sevdiğiniz hizmeti %20 indirimli alın.',              'tip' => 'hizmet_indirimi', 'deger' => 20],
        ['puan_esigi' => 400,  'baslik' => '%15 Ürün İndirimi',    'aciklama' => 'Tüm bakım ürünlerinde geçerli %15 indirim.',         'tip' => 'urun_indirimi',   'deger' => 15],
        ['puan_esigi' => 600,  'baslik' => '%30 Hizmet İndirimi',  'aciklama' => 'Premium hizmet indirimi — sınırlı zaman.',           'tip' => 'hizmet_indirimi', 'deger' => 30],
        ['puan_esigi' => 1000, 'baslik' => 'Ücretsiz Saç Bakımı',  'aciklama' => 'Tamamen ücretsiz profesyonel saç bakımı paketi.',    'tip' => 'hediye',          'deger' => null],
        ['puan_esigi' => 1500, 'baslik' => 'Ücretsiz Cilt Bakımı', 'aciklama' => 'Yüz analizi + cilt bakımı (50 dk) hediyemizdir.',    'tip' => 'hediye',          'deger' => null],
    ],
];
