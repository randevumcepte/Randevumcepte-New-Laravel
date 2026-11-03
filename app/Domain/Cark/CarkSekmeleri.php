<?php

namespace App\Domain\Cark;

/**
 * Çarkıfelek sayfasının SEKME KAYIT DEFTERİ — eski 4 ayrı sayfa (carkifelek, carkkazananlar,
 * puanodulleri, carkhatirlatma; partials/carkifelek_tabs) tek sayfada. Web (lib/v2-sekme.js)
 * ve Flutter (TabBar) aynı listeyi GET /cark'tan çizer.
 *
 * YENİ SEKME: buraya tek satır + web public/js/carkifelek/<kod>.js modülü ve
 * carkifelek-v2.js MODULLER haritasına tek satır (Flutter: aynı kod için sekme gövdesi).
 */
final class CarkSekmeleri
{
    private const TANIMLAR = [
        ['kod' => 'cark', 'ad' => 'Çarkıfelek', 'ikon' => 'bi-bullseye',
            'aciklama' => 'Dilimlerinizi düzenleyin, müşterilerinize özel ödüller sunun.'],
        ['kod' => 'kazananlar', 'ad' => 'Çark Kazananlar', 'ikon' => 'bi-trophy',
            'aciklama' => 'Çevirme geçmişi ve kuponlar; müşteri kodunu doğrulayıp kullanım onayı verin.'],
        ['kod' => 'puan', 'ad' => 'Puan Ödülleri', 'ikon' => 'bi-star',
            'aciklama' => 'Puan kazanma kuralı ve puanla alınabilen ödül merdiveni.'],
        ['kod' => 'hatirlatma', 'ad' => 'Hatırlatma', 'ikon' => 'bi-bell',
            'aciklama' => 'Gün içinde en fazla 3 push ile çark hakkını hatırlatın.'],
    ];

    public static function liste(): array
    {
        return self::TANIMLAR;
    }
}
