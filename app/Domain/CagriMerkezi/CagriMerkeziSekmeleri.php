<?php

namespace App\Domain\CagriMerkezi;

/**
 * Çağrı Merkezi sayfasının SEKME KAYIT DEFTERİ — eski 4 ayrı sayfa (arama-listelerim,
 * arama-randevu-takvim, arama-cagri-kayitlari, arama-dashboard[+Liste Yönetimi]) tek sayfada.
 *
 * Hangi sekmenin kime görüneceği SUNUCUDA karar verilir; web (lib/v2-sekme.js) ve Flutter
 * (TabBar) aynı listeyi çizer. YENİ SEKME EKLEMEK:
 *   1) Buraya tek satır (kod, ad, ikon, yalniz_yonetici)
 *   2) Web: public/js/cagri-merkezi/<kod>.js modülü (export async function ciz(kap, ctx))
 *      ve cagri-merkezi-v2.js MODULLER haritasına tek satır
 *   3) Flutter: aynı `kod` için sekme gövdesi
 * Uçlar / erişim kuralı değişmez.
 */
final class CagriMerkeziSekmeleri
{
    /** @var array<int, array{kod:string, ad:string, ikon:string, yalniz_yonetici:bool, aciklama:string}> */
    private const TANIMLAR = [
        ['kod' => 'calisma', 'ad' => 'Çalışma Ekranı', 'ikon' => 'bi-headset', 'yalniz_yonetici' => false,
            'aciklama' => 'Atanan listelerden müşterileri arayın, görüşme sonucunu ve notunu kaydedin.'],
        ['kod' => 'takvim', 'ad' => 'Arama Randevuları', 'ikon' => 'bi-calendar-check', 'yalniz_yonetici' => false,
            'aciklama' => 'Seçilen güne ait arama randevuları personele göre, saat sırasıyla.'],
        ['kod' => 'kayitlar', 'ad' => 'Çağrı Kayıtları', 'ikon' => 'bi-telephone', 'yalniz_yonetici' => false,
            'aciklama' => 'Santraldeki gerçek çağrı geçmişi ve ses kayıtları.'],
        ['kod' => 'performans', 'ad' => 'Performans', 'ikon' => 'bi-bar-chart', 'yalniz_yonetici' => true,
            'aciklama' => 'Personel bazlı atanan data, aranan, görüşme ve dönüşüm takibi.'],
        ['kod' => 'listeler', 'ad' => 'Liste Yönetimi', 'ikon' => 'bi-sliders', 'yalniz_yonetici' => true,
            'aciklama' => 'Listeyi sonuca göre ayır, indir, başka personele taşı, arşivle.'],
    ];

    /** Erişime göre görünür sekmeler (sıra korunur). */
    public static function gorunur(CagriMerkeziErisim $erisim): array
    {
        return array_values(array_map(
            fn ($t) => array_diff_key($t, ['yalniz_yonetici' => 1]),
            array_filter(self::TANIMLAR, fn ($t) => $erisim->yonetici || ! $t['yalniz_yonetici']),
        ));
    }

    /** Sekme kodu bu kullanıcıya açık mı? (uç düzeyinde yönetici kapısı için de kullanılır) */
    public static function acikMi(string $kod, CagriMerkeziErisim $erisim): bool
    {
        return in_array($kod, array_column(self::gorunur($erisim), 'kod'), true);
    }
}
