<?php

namespace App\Domain\Ayar;

/**
 * Ayarlar ekrani sozlugu — sekmeler (yetki OR-gate), form secenekleri, sinirlar.
 * TEK KAYNAK: web ve Flutter sekme/secenek listesi TUTMAZ, /ayar/ozet ve GET uclarindan okur.
 *
 * Sekme → yetki eslemesi eski StoreAdminController@ayarlar ile BIREBIR.
 * 'v2' = false olan sekmeler henuz tasinmadi; istemci eski panel linkini (eski_p) acar.
 */
final class AyarSozluk
{
    public const SEKMELER = [
        'isletme'           => ['ad' => 'İşletme Bilgileri',      'ikon' => 'bi-shop',            'yetki' => ['ayar.salon_bilgi'],      'v2' => true,  'eski_p' => 'temelbilgiler'],
        'subeler'           => ['ad' => 'Şubeler',                'ikon' => 'bi-diagram-3',       'yetki' => ['ayar.sube_yonet'],       'v2' => true,  'eski_p' => 'subeler'],
        'calisma_saatleri'  => ['ad' => 'Çalışma Saatleri',       'ikon' => 'bi-clock',           'yetki' => ['ayar.salon_bilgi'],      'v2' => true,  'eski_p' => 'calismasaatleri'],
        'cihazlar'          => ['ad' => 'Cihazlar',               'ikon' => 'bi-cpu',             'yetki' => ['ayar.cihaz_oda_yonet'],  'v2' => true,  'eski_p' => 'cihazlar'],
        'hizmetler'         => ['ad' => 'Hizmetler',              'ikon' => 'bi-scissors',        'yetki' => ['hizmet.tanim_olustur', 'hizmet.kategori_yonet'], 'v2' => true,  'eski_p' => 'hizmetler'],
        'odalar'            => ['ad' => 'Odalar',                 'ikon' => 'bi-door-closed',     'yetki' => ['ayar.cihaz_oda_yonet'],  'v2' => true,  'eski_p' => 'odalar'],
        'randevu'           => ['ad' => 'Randevu Ayarları',       'ikon' => 'bi-calendar-check',  'yetki' => ['randevu.online_ayar'],   'v2' => true,  'eski_p' => 'randevuayarlari'],
        'online_saatler'    => ['ad' => 'Online Randevu Saatleri', 'ikon' => 'bi-globe2',         'yetki' => ['randevu.online_ayar'],   'v2' => true,  'eski_p' => 'onlinerandevusaatleri'],
        'musteri_indirimleri' => ['ad' => 'Müşteri İndirimleri',  'ikon' => 'bi-percent',         'yetki' => ['satis.indirim_uygula'],  'v2' => true,  'eski_p' => 'musteri_indirimleri'],
        'entegrasyonlar'    => ['ad' => 'Entegrasyonlar',         'ikon' => 'bi-google',          'yetki' => [],                        'v2' => false, 'eski_p' => 'entegrasyonlar'],
    ];

    public const SAAT_ARALIKLARI = [15, 30, 45, 60, 90, 120];

    /** randevu_takvim_turu degerleri — eski blade secenek sirasi korunur. */
    public const TAKVIM_TURLERI = [
        ['kod' => 1, 'ad' => 'Personele Göre'],
        ['kod' => 0, 'ad' => 'Hizmet Kategorisine Göre'],
        ['kod' => 2, 'ad' => 'Cihaza Göre'],
        ['kod' => 3, 'ad' => 'Odaya Göre'],
    ];

    /** Online randevu istisna tipleri. */
    public const ISTISNA_TIPLERI = [
        ['kod' => 'kapali', 'ad' => 'Kapalı (online randevu yok)'],
        ['kod' => 'ozel',   'ad' => 'Özel saat aralığı'],
    ];

    /** hizmetler.cinsiyet degerleri (eski hizmet paneli rozetleri). */
    public const CINSIYETLER = [
        ['kod' => 0, 'ad' => 'Kadın'],
        ['kod' => 1, 'ad' => 'Erkek'],
        ['kod' => 2, 'ad' => 'Unisex'],
    ];

    /** Sistem havuzunda eklenebilir listesine hic girmeyen hizmet (eski kodla ayni istisna). */
    public const HAVUZ_HARIC_HIZMET_ID = 463;

    /** Kapak fotografi haric galeri gorseli tavani (eski isletmegorselekle ile ayni). */
    public const GALERI_MAKS = 12;

    public const ACIKLAMA_MAKS = 1500;

    /** SEO anahtar kelime kutusu sayisi (eski blade 6'ya tamamlar). */
    public const ANAHTAR_KELIME_MAKS = 6;

    /** Sekmenin yetki anahtarlari; bilinmeyen sekme LogicException (gelistirici hatasi). */
    public static function yetki(string $sekme): array
    {
        if (! isset(self::SEKMELER[$sekme])) {
            throw new \LogicException('Bilinmeyen ayar sekmesi: '.$sekme);
        }

        return self::SEKMELER[$sekme]['yetki'];
    }

    public static function cinsiyetEtiketi($kod): ?string
    {
        foreach (self::CINSIYETLER as $c) {
            if ($kod !== null && $kod !== '' && (int) $kod === $c['kod']) {
                return $c['ad'];
            }
        }

        return null;
    }

    public static function takvimTuruGecerli($kod): bool
    {
        return in_array((int) $kod, array_column(self::TAKVIM_TURLERI, 'kod'), true);
    }
}
