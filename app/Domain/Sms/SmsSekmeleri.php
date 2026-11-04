<?php

namespace App\Domain\Sms;

/**
 * SMS Yönetimi SEKME KAYIT DEFTERİ + sayfa metni. Web (lib/v2-sekme.js) ve Flutter (TabBar) aynı
 * listeyi GET /sms/acilis'ten çizer; görünürlük burada (eski blade @if'leri).
 *
 * YENİ SEKME: buraya tek satır + web public/js/sms/<kod>.js ve sms-yonetimi-v2.js MODULLER'e tek satır.
 */
final class SmsSekmeleri
{
    public const YASAL_UYARI = 'Yaptığınız SMS gönderimlerinin tüm yasal ve cezai yükümlülüğü size ait olup, Elektronik Ticaretin Düzenlenmesi Hakkında Kanun uyarınca, ticari maksatlı SMS gönderimi yapacağınız numaralardan daha önce ticari ileti izni alarak firmanıza ait İleti Yönetim Sistemi hesabınıza (İYS) kayıt etmiş olmanız gerekmektedir. Ticari ileti izni aldığınız numaralara gönderim yaparken, ücretsiz bir numaraya SMS göndererek SMS ret seçeceği sunulması ve firmanıza ait MERSİS numarasının gönderilen SMS içeriğinde belirtilmesi kanunen zorunludur. İzinsiz veya gerekli yükümlülükler yerine getirilmeksizin yapılan gönderimler için alıcıların şikayet etmesi durumunda, İl Ticaret Müdürlükleri tarafından gönderilen her bir SMS için yüklü miktarda para cezaları kesilmektedir.';

    /** @param array{ayar:bool} $yetkiler  Sayfaya giren herkes şablonları görür (erişim kapısı aynı). */
    public static function gorunur(array $yetkiler): array
    {
        return array_values(array_filter([
            ['kod' => 'raporlar', 'ad' => 'SMS Raporları', 'ikon' => 'bi-bar-chart-line'],
            ['kod' => 'toplu', 'ad' => 'Şablon Ayarları ve Toplu SMS', 'ikon' => 'bi-send'],
            $yetkiler['ayar'] ? ['kod' => 'ayarlar', 'ad' => 'SMS Ayarları', 'ikon' => 'bi-sliders'] : null,
            ['kod' => 'kara_liste', 'ad' => 'SMS Kara Liste', 'ikon' => 'bi-slash-circle'],
        ]));
    }
}
