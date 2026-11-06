<?php

namespace App\Support;

use App\Domain\SistemYonetim\SistemRolu;

/** Sistem yonetimi sol menusu — tek kaynak; rol suzmesi sunucuda (web ve Flutter ayni listeyi alir). */
final class SistemMenu
{
    private const YONETIM = [SistemRolu::SuperAdmin, SistemRolu::Yonetici];

    /** @return list<array{bolum:string,kod:string,etiket:string,ikon:string,url:string,rozet?:string,harici?:bool}> */
    public static function rolIcin(SistemRolu $rol): array
    {
        $ogeler = [
            ['Genel', 'dashboard', 'Dashboard', 'mdi-view-dashboard', '/sistemyonetim/dashboard'],
            ['Genel', 'salonlar', 'Salonlar', 'mdi-store', '/sistemyonetim/salonlar'],
            ['Genel', 'dakika', 'Dakika Yönetimi', 'mdi-phone-clock', '/sistemyonetim/dakika'],
            ['Genel', 'aikredi', 'AI Kredi & Kullanım', 'mdi-robot-happy', '/sistemyonetim/ai-kredi'],
            ['Genel', 'asistanegitim', 'Asistan Eğitimi', 'mdi-school', '/sistemyonetim/asistan-egitim'],
            ['Genel', 'ticket', 'Destek Talepleri', 'mdi-lifebuoy', '/sistemyonetim/ticket', 'bekleyen_ticket'],
            ['Genel', 'whatsapp', 'WhatsApp Yönetim', 'mdi-whatsapp', '/sistemyonetim/whatsapp'],
            ['Genel', 'sistem-whatsapp', 'Sistem WhatsApp', 'mdi-bell-ring', '/sistemyonetim/sistem-whatsapp', null, [SistemRolu::SuperAdmin]],
            ['Genel', 'duyuru', 'Duyurular', 'mdi-bullhorn', '/sistemyonetim/duyuru'],
            ['Genel', 'risk', 'Risk Altındakiler', 'mdi-alert-circle', '/sistemyonetim/risk'],
            ['Genel', 'hazircevap', 'Hazır Cevaplar', 'mdi-message-text-fast', '/sistemyonetim/hazir-cevap'],
            ['Genel', 'manuelodeme', 'Manuel Ödeme Linki', 'mdi-link-variant', '/sistemyonetim/manuel-odeme-linki'],
            ['Genel', 'smspaket', 'Duyuru Paketleri', 'mdi-message-text', '/sistemyonetim/sms-paket'],
            ['Genel', 'duyurusiparis', 'Paket Siparişleri', 'mdi-cart-check', '/sistemyonetim/duyuru-paketi-siparisleri'],
            ['Ekip & İzleme', 'ekip', 'Ekip & Roller', 'mdi-account-group', '/sistemyonetim/ekip', null, self::YONETIM],
            ['Ekip & İzleme', 'performans', 'Ekip Performansı', 'mdi-chart-line', '/sistemyonetim/performans', null, self::YONETIM],
            ['Ekip & İzleme', 'aktivite', 'Aktivite Logu', 'mdi-timeline-clock', '/sistemyonetim/aktivite-log', null, self::YONETIM],
            ['Ekip & İzleme', 'guvenlik', 'Güvenlik', 'mdi-shield-account', '/sistemyonetim/guvenlik/girisler', null, self::YONETIM],
            ['Ekip & İzleme', 'guvenlik-duvari', 'Güvenlik Duvarı', 'mdi-shield-lock', '/sistemyonetim/guvenlik-duvari', null, self::YONETIM],
            ['Ekip & İzleme', 'saglik', 'Sistem Sağlık', 'mdi-pulse', '/sistemyonetim/sistem-saglik', null, self::YONETIM],
        ];

        $liste = [];
        foreach ($ogeler as $o) {
            $roller = $o[6] ?? null;
            if ($roller !== null && ! in_array($rol, $roller, true)) {
                continue;
            }
            $liste[] = ['bolum' => $o[0], 'kod' => $o[1], 'etiket' => $o[2], 'ikon' => $o[3], 'url' => $o[4]] + (isset($o[5]) ? ['rozet' => $o[5]] : []);
        }
        // Avantaj/hizmet havuzu gibi tasinmamis ekranlar eski panelde kaldi.
        $liste[] = ['bolum' => 'Eski Panel', 'kod' => 'klasik', 'etiket' => 'Klasik Görünüm', 'ikon' => 'mdi-arrow-left', 'url' => rtrim((string) config('panel.eski_panel_url'), '/').'/sistemyonetim/isletmeler', 'harici' => true];

        return $liste;
    }
}
