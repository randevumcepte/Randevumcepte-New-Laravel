<?php

namespace App\Support;

/**
 * Panel sol menü TANIMI — tek kaynak (single source of truth).
 *
 * Eski monolitin (layout_isletmeadmin.blade.php) sidebar menüsüyle BİREBİR öğe
 * sırası / etiket / ikon / rota eşlemesi. Linkler eski panele (app.*) gider;
 * böylece v2 ekranı eski panelle PARALEL kullanılır (geri dönüş + gezinme).
 *
 * Her öğe:
 *   etiket      : görünen ad (Müşteriler/Danışanlar gibi salona göre değişenler servis'te çözülür)
 *   ikon        : bootstrap-icons sınıfı (eski menüyle aynı)
 *   slug        : /isletmeyonetim/<slug> (boş => /isletmeyonetim dashboard)
 *   v2_rota     : set ise link ESKİ PANELE değil AYNI host'taki v2 sayfasına gider
 *                 (göreli, örn. /v2/satislar?sube=) — strangler ekranları için
 *   ek_sorgu    : sabit ekstra query (örn. ayarlar?p=temelbilgiler)
 *   pageindex   : aktif-vurgu eşleştirme (opsiyonel; v2 sayfası $aktif slug ile de vurgular)
 *   yetki       : gereken menuYetki anahtar(lar)ı (OR-gate; boşsa herkese açık)
 *   ozellik     : salon özelliği bayrağı (santral_aktif/grup_dersi_aktif/uyelik_turu_premium)
 *   studyo_gizle: studyo_modu açıksa gizle
 *   rol5_gizle  : rol 5 (personel) kullanıcıdan gizle
 *   bolum       : görsel gruplama başlığı (modern menüde bölüm etiketi)
 *
 * NOT: Gerçek erişim denetimi her zaman eski panelde yeniden yapılır; buradaki
 * filtreleme yalnızca kullanıcıya gereksiz öğe göstermemek içindir (eski menüyle
 * tutarlı görünüm).
 */
class PanelMenu
{
    /** @return array<int,array<string,mixed>> */
    public static function tanim(): array
    {
        return [
            // ───────────── Genel
            ['etiket' => 'Özet',               'ikon' => 'bi bi-grid-1x2-fill',    'slug' => '',                  'pageindex' => 0,  'rol5_gizle' => true, 'bolum' => 'Genel'],
            ['etiket' => 'Asistanım',          'ikon' => 'bi bi-card-checklist',   'slug' => 'e_asistan',         'pageindex' => 60, 'ozellik' => 'uyelik_turu_premium', 'rol5_gizle' => true],
            ['etiket' => 'Santral',            'ikon' => 'bi bi-phone',            'slug' => 'santral',           'pageindex' => 43, 'ozellik' => 'santral_aktif',      'rol5_gizle' => true],

            // ───────────── Randevu
            ['etiket' => 'Randevu Takvimi',    'ikon' => 'bi bi-calendar4-week',   'slug' => 'randevular',        'pageindex' => 2,  'yetki' => 'randevu.takvim_gor', 'bolum' => 'Randevu'],
            // v2 onizleme (EventCalendar) — durum yazma RANDEVU_V2_YAZMA_AKTIF acilana kadar DRY-RUN.
            ['etiket' => 'Takvim v2 (Önizleme)', 'ikon' => 'bi bi-calendar2-range', 'slug' => 'takvim-v2',       'v2_rota' => '/v2/takvim', 'pageindex' => 2, 'yetki' => 'randevu.takvim_gor'],
            ['etiket' => 'Ders Programı',      'ikon' => 'bi bi-grid-3x3-gap-fill','slug' => 'ders-programi',     'pageindex' => 205,'yetki' => 'randevu.takvim_gor', 'ozellik' => 'grup_dersi_aktif'],
            ['etiket' => 'Grup Dersi Raporu',  'ikon' => 'bi bi-bar-chart-fill',   'slug' => 'grup-dersi-raporu', 'pageindex' => 206,'yetki' => 'randevu.takvim_gor', 'ozellik' => 'grup_dersi_aktif'],
            ['etiket' => 'Ön Görüşmeler',      'ikon' => 'bi bi-chat-left-text',   'slug' => 'ongorusmeler',      'pageindex' => 12, 'yetki' => 'gorusme.liste_gor', 'studyo_gizle' => true],
            ['etiket' => 'Randevular',         'ikon' => 'bi bi-card-heading',     'slug' => 'randevular-liste',  'pageindex' => 3],
            ['etiket' => 'Ajanda',             'ikon' => 'bi bi-calendar-event',   'slug' => 'ajanda',            'pageindex' => 70, 'yetki' => 'randevu.takvim_gor'],

            // ───────────── Satış & Finans
            ['etiket' => 'Satış & Tahsilat',   'ikon' => 'bi bi-stars',            'slug' => 'tahsilat-modern',   'pageindex' => 1111,'yetki' => 'satis.tahsilat_al', 'bolum' => 'Satış & Finans'],
            // v2 ekran (aynı host, göreli link) — eski "Satış Takibi" (adisyonlar) karşılığı.
            ['etiket' => 'Satış Takibi',       'ikon' => 'bi bi-receipt',          'slug' => 'satis-takibi',      'v2_rota' => '/v2/satislar', 'pageindex' => 1112, 'yetki' => ['satis.tum_satis_gor', 'satis.adisyon_olustur', 'satis.tahsilat_al']],
            ['etiket' => 'Adisyonlar',         'ikon' => 'bi bi-files',            'slug' => 'adisyonlar',        'pageindex' => 111, 'yetki' => 'satis.tum_satis_gor'],
            ['etiket' => 'Paket Yönetimi',     'ikon' => 'bi bi-grid-3x3',         'slug' => 'paketsatislari',    'pageindex' => 4,   'yetki' => 'paket.tanim_olustur'],
            ['etiket' => 'Seans Takibi',       'ikon' => 'bi bi-check2-square',    'slug' => 'seanstakip',        'pageindex' => 30,  'yetki' => 'paket.seans_takip'],
            ['etiket' => 'Stok Yönetimi',      'ikon' => 'bi bi-tags',             'slug' => 'urunler',           'pageindex' => 20,  'yetki' => 'urun.tanim_olustur'],
            ['etiket' => 'Senet Takibi',       'ikon' => 'bi bi-clipboard2',       'slug' => 'senetler',          'pageindex' => 40,  'yetki' => 'satis.senet_olustur'],
            ['etiket' => 'Kasa Raporu',        'ikon' => 'bi bi-cash-stack',       'slug' => 'kasadefteri',       'pageindex' => 41,  'yetki' => 'rapor.kasa'],
            ['etiket' => 'İşletme Raporları',  'ikon' => 'bi bi-graph-up-arrow',   'slug' => 'isletmeraporlari',  'pageindex' => 42,  'yetki' => 'rapor.satis'],
            ['etiket' => 'Raporlar',           'ikon' => 'bi bi-pie-chart',        'slug' => 'raporlar',          'pageindex' => 43,  'yetki' => 'rapor.satis'],

            // ───────────── Müşteri & Ekip
            ['etiket' => 'Müşteriler',         'ikon' => 'bi bi-people',           'slug' => 'musteriler',        'pageindex' => 10,  'yetki' => 'musteri.liste_gor', 'bolum' => 'Müşteri & Ekip'],
            ['etiket' => 'Personeller',        'ikon' => 'bi bi-person-lines-fill','slug' => 'personel-yonetimi', 'v2_rota' => '/v2/personel', 'pageindex' => 5,   'yetki' => 'personel.liste_gor'],

            // ───────────── Pazarlama
            ['etiket' => 'Memnuniyet Anketi',  'ikon' => 'bi bi-chat-dots',        'slug' => 'anket-sonuclari',   'pageindex' => 80,  'yetki' => 'pazarlama.anket_yonet', 'bolum' => 'Pazarlama'],
            ['etiket' => 'Bildirim Reklamları','ikon' => 'bi bi-megaphone',        'slug' => 'bildirim-reklamlari','pageindex' => 81, 'yetki' => 'pazarlama.kampanya_yonet'],
            ['etiket' => 'Reklam Yönetimi',    'ikon' => 'bi bi-cash-coin',        'slug' => 'kampanya_yonetimi', 'pageindex' => 82,  'yetki' => 'pazarlama.kampanya_yonet'],
            ['etiket' => 'Etkinlikler',        'ikon' => 'bi bi-text-left',        'slug' => 'etkinlik',          'pageindex' => 83,  'yetki' => 'pazarlama.kampanya_yonet'],
            ['etiket' => 'Çarkıfelek',         'ikon' => 'bi bi-dpad',             'slug' => 'carkifelek',        'pageindex' => 84,  'yetki' => 'pazarlama.cark_yonet'],
            ['etiket' => 'Uygulama Afişi',     'ikon' => 'bi bi-qr-code',          'slug' => 'uygulama-afisi',    'pageindex' => 85,  'yetki' => 'pazarlama.kampanya_yonet'],
            ['etiket' => 'WhatsApp Yönetimi',  'ikon' => 'bi bi-whatsapp',         'slug' => 'whatsapp',          'pageindex' => 90,  'yetki' => 'pazarlama.whatsapp_gonder'],
            ['etiket' => 'SMS Yönetimi',       'ikon' => 'bi bi-chat-square-text', 'slug' => 'toplusms',          'pageindex' => 91,  'yetki' => 'pazarlama.toplu_sms'],

            // ───────────── Çağrı Merkezi
            ['etiket' => 'Çağrı Merkezi',      'ikon' => 'bi bi-headset',          'slug' => 'arama-dashboard',       'pageindex' => 100, 'ozellik' => 'santral_aktif', 'bolum' => 'Çağrı Merkezi'],
            ['etiket' => 'Arama Randevu Takvimi','ikon' => 'bi bi-calendar-check', 'slug' => 'arama-randevu-takvim',  'pageindex' => 101, 'ozellik' => 'santral_aktif'],
            ['etiket' => 'Çağrı Kayıtları',    'ikon' => 'bi bi-telephone',        'slug' => 'arama-cagri-kayitlari', 'pageindex' => 102, 'ozellik' => 'santral_aktif'],
            ['etiket' => 'Atanan Aramalar',    'ikon' => 'bi bi-telephone-outbound','slug' => 'arama-listelerim',     'pageindex' => 103, 'ozellik' => 'santral_aktif'],

            // ───────────── Sistem
            ['etiket' => 'Ayarlar',            'ikon' => 'bi bi-gear',             'slug' => 'ayarlar', 'ek_sorgu' => 'p=temelbilgiler', 'pageindex' => 9, 'yetki' => 'ayar.salon_bilgi', 'bolum' => 'Sistem'],
            ['etiket' => 'Log Hareketleri',    'ikon' => 'bi bi-clock-history',    'slug' => 'log-hareketleri',   'pageindex' => 110, 'yetki' => 'ayar.salon_bilgi'],
        ];
    }
}
