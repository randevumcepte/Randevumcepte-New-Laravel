<?php

namespace App\Support;

use App\Models\Salonlar;

/**
 * Header kısayolları — TEK kaynak (web + Flutter /api/v2/kabuk'tan aynı listeyi görür).
 * Öğe biçimi ve görünürlük anahtarları PanelMenu ile aynıdır (yetki, ozellik, rol5_gizle,
 * studyo_gizle, v2_rota); süzme MenuGorunurluk'ta.
 *
 *   hizliEkle(): "+" menüsü. v2_rota'sı olmayan öğe bir form açar — istemci `kod`a göre
 *                kendi formunu gösterir (web: js/hizli-ekle.js).
 *   kullanici(): avatar menüsü.
 *
 * Eski layout'tan bilinçli fark: Yeni Randevu / Ön Görüşme / Form Gönder de formun API yetkisine
 * bağlıdır (eskide görünüp kayıtta reddediliyordu).
 */
final class PanelKisayollar
{
    public static function musteriEtiketi(object $salon): string
    {
        return in_array((int) ($salon->salon_turu_id ?? 0), Salonlar::DANISAN_TURLERI, true) ? 'Danışan' : 'Müşteri';
    }

    /** @return list<array<string,mixed>> */
    public static function hizliEkle(object $salon): array
    {
        $studyo = (int) ($salon->studyo_modu ?? 0) === 1;

        return [
            ['kod' => 'randevu',   'etiket' => 'Yeni Randevu',     'ikon' => 'bi bi-calendar-plus', 'yetki' => 'randevu.olustur'],
            ['kod' => 'ongorusme', 'etiket' => 'Yeni Ön Görüşme',  'ikon' => 'bi bi-calendar-event', 'yetki' => 'gorusme.ekle_duzenle'],
            ['kod' => 'musteri',   'etiket' => 'Yeni '.self::musteriEtiketi($salon), 'ikon' => 'bi bi-person-plus', 'yetki' => 'musteri.ekle_duzenle'],
            ['kod' => 'satis',     'etiket' => $studyo ? 'Yeni Satış' : 'Yeni Satış & Tahsilat', 'ikon' => 'bi bi-cart-plus', 'v2_rota' => '/v2/yeni-satis', 'yetki' => 'satis.adisyon_olustur', 'ozellik' => 'uyelik_turu_paketli'],
            ['kod' => 'masraf',    'etiket' => 'Yeni Masraf',      'ikon' => 'bi bi-receipt-cutoff', 'yetki' => 'finans.masraf_ekle', 'ozellik' => 'uyelik_turu_paketli', 'studyo_gizle' => true],
            ['kod' => 'form',      'etiket' => 'Form Gönder',      'ikon' => 'bi bi-send', 'yetki' => ['form.olustur', 'form.gonder'], 'rol5_gizle' => true],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function kullanici(): array
    {
        return [
            ['kod' => 'profil',  'etiket' => 'Profil Bilgileri',    'ikon' => 'bi bi-person',       'v2_rota' => '/v2/profil'],
            ['kod' => 'hesabim', 'etiket' => 'Hesabım & Faturalar', 'ikon' => 'bi bi-person-vcard', 'v2_rota' => '/v2/hesabim', 'rol5_gizle' => true],
            ['kod' => 'cikis',   'etiket' => 'Çıkış Yap',           'ikon' => 'bi bi-box-arrow-right', 'url' => EskiPanelLinki::url('isletmeyonetim/cikisyap')],
        ];
    }
}
