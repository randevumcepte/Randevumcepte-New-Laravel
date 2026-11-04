<?php

namespace App\Domain\Whatsapp;

/**
 * WhatsApp Yönetimi sayfasının kayıt defteri: sekmeler + "Nasıl Çalışır?" metinleri. Web
 * (lib/v2-sekme.js) ve Flutter (TabBar) aynı listeyi GET /whatsapp'tan çizer.
 *
 * YENİ SEKME: buraya tek satır + web public/js/whatsapp/<kod>.js modülü ve whatsapp-v2.js
 * MODULLER haritasına tek satır (Flutter: aynı kod için sekme gövdesi).
 */
final class WhatsappSayfasi
{
    private const SEKMELER = [
        ['kod' => 'baglanti', 'ad' => 'Bağlantı', 'ikon' => 'bi-link-45deg'],
        ['kod' => 'kontor', 'ad' => 'Kontör', 'ikon' => 'bi-coin'],
        ['kod' => 'istatistik', 'ad' => 'İstatistik', 'ikon' => 'bi-bar-chart'],
        ['kod' => 'mesajlar', 'ad' => 'Mesajlarım', 'ikon' => 'bi-chat-left-text'],
        ['kod' => 'aliciler', 'ad' => 'Alıcılarım', 'ikon' => 'bi-people'],
    ];

    private const NASIL_CALISIR = [
        'WhatsApp bağlandıktan sonra SMS Ayarları\'nda "Müşteri" işaretli randevu hatırlatmaları, müşterilerinize kendi WhatsApp numaranız üzerinden iletilir.',
        "Müşterinin WhatsApp'ı yoksa veya iletilemezse, mesaj otomatik SMS olarak gider — hiçbir hatırlatma kaybolmaz.",
        'Mesajlar müşterilere doğal aralıklarla, kişiselleştirilmiş şekilde iletilir (her müşteriye özel selamlama ve metin).',
        'İlk hafta hazırlık dönemi: Numaranızın WhatsApp Business sisteminde stabil çalışması için ilk 7 gün günlük gönderim sayısı kademeli artar (1. gün 15, 7. gün tam kapasite).',
        '1 gün öncesi hatırlatma: Yarınki tüm randevular için bugün 12:00-17:00 arasında bir kez gönderilir. Müşteri iptal/erteleme isterse salonun açık olduğu saatlerde sizi arayabilir.',
        'Çalışma saatleri: Müşterilerinize gece geç saatte rahatsız edici mesaj gitmemesi için gönderimler gündüz saatlerinde yapılır.',
        'Bağlantı koparsa veya bir sorun olduğunda panel size haber verir, hatırlatmalar otomatik SMS\'e geçer.',
        'İpucu: En az 2 haftadır kullanılan, WhatsApp Business uygulaması yüklü bir numara bağlamak en sağlıklı sonucu verir.',
    ];

    public static function sekmeler(): array
    {
        return self::SEKMELER;
    }

    /** @return list<string> */
    public static function nasilCalisir(): array
    {
        return self::NASIL_CALISIR;
    }
}
