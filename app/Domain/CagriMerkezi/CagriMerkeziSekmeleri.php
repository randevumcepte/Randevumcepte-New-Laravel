<?php

namespace App\Domain\CagriMerkezi;

/**
 * Çağrı Merkezi sayfasının SEKME KAYIT DEFTERİ — eski 4 ayrı sayfa (arama-listelerim,
 * arama-randevu-takvim, arama-cagri-kayitlari, arama-dashboard[+Liste Yönetimi]) + arama-randevularim (ajanda) + cagri-ayarlari tek sayfada.
 *
 * Hangi sekmenin kime görüneceği SUNUCUDA karar verilir; web (lib/v2-sekme.js) ve Flutter
 * (TabBar) aynı listeyi çizer. YENİ SEKME EKLEMEK:
 *   1) Buraya tek satır (kod, ad, ikon, eylem)
 *   2) Web: public/js/cagri-merkezi/<kod>.js modülü (export async function ciz(kap, ctx))
 *      ve cagri-merkezi-v2.js MODULLER haritasına tek satır
 *   3) Flutter: aynı `kod` için sekme gövdesi
 * Uçlar / erişim kuralı değişmez.
 */
final class CagriMerkeziSekmeleri
{
    /** @var array<int, array{kod:string, ad:string, ikon:string, eylem:CagriMerkeziEylemi, aciklama:string}> */
    private const TANIMLAR = [
        ['kod' => 'calisma', 'ad' => 'Çalışma Ekranı', 'ikon' => 'bi-headset', 'eylem' => CagriMerkeziEylemi::Calisma,
            'aciklama' => 'Atanan listelerden müşterileri arayın, görüşme sonucunu ve notunu kaydedin.'],
        ['kod' => 'ajanda', 'ad' => 'Arama Randevularım', 'ikon' => 'bi-alarm', 'eylem' => CagriMerkeziEylemi::AramaRandevu,
            'aciklama' => 'Bekleyen arama randevularınız; seçilen günün dökümü, arama ve erteleme.'],
        ['kod' => 'takvim', 'ad' => 'Arama Randevuları', 'ikon' => 'bi-calendar-check', 'eylem' => CagriMerkeziEylemi::AramaRandevu,
            'aciklama' => 'Seçilen güne ait arama randevuları personele göre, saat sırasıyla.'],
        ['kod' => 'kayitlar', 'ad' => 'Çağrı Kayıtları', 'ikon' => 'bi-telephone', 'eylem' => CagriMerkeziEylemi::CagriKayitlari,
            'aciklama' => 'Santraldeki gerçek çağrı geçmişi ve ses kayıtları.'],
        ['kod' => 'performans', 'ad' => 'Performans', 'ikon' => 'bi-bar-chart', 'eylem' => CagriMerkeziEylemi::Performans,
            'aciklama' => 'Personel bazlı atanan data, aranan, görüşme ve dönüşüm takibi.'],
        ['kod' => 'listeler', 'ad' => 'Liste Yönetimi', 'ikon' => 'bi-sliders', 'eylem' => CagriMerkeziEylemi::ListeYonet,
            'aciklama' => 'Filtreyle yeni arama listesi oluştur; listeyi sonuca göre ayır, indir, taşı, arşivle.'],
        ['kod' => 'ayarlar', 'ad' => 'Ayarlar', 'ikon' => 'bi-gear', 'eylem' => CagriMerkeziEylemi::AyarYonet,
            'aciklama' => 'Görüşme scriptleri ve sonuç kategorileri.'],
    ];

    /** Erişime göre görünür sekmeler (sıra korunur). */
    public static function gorunur(CagriMerkeziErisim $erisim): array
    {
        return array_values(array_map(
            fn ($t) => array_diff_key($t, ['eylem' => 1]),
            array_filter(self::TANIMLAR, fn ($t) => $erisim->yapabilir($t['eylem'])),
        ));
    }

    /** Sekme kodu bu kullanıcıya açık mı? (uç düzeyinde yönetici kapısı için de kullanılır) */
    public static function acikMi(string $kod, CagriMerkeziErisim $erisim): bool
    {
        return in_array($kod, array_column(self::gorunur($erisim), 'kod'), true);
    }
}
