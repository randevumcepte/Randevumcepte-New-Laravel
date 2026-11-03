<?php

namespace App\Domain\CagriMerkezi;

/**
 * Aranacak müşterinin GÖRÜŞME SONUCU (aranacak_musteriler.durum / gorusme_notlari.sonuc) — TEK sözlük.
 *
 * Eskiden aynı kod→etiket/renk eşlemesi 5 yerde (StoreAdminController::aranacakDurumMetin,
 * arama_listelerim durStil, arama_dashboard pdSonucStil, takvim renkleri, Flutter) ayrı ayrı ve
 * farklı eksiklerle yazılıydı. v2'de etiket, renk tokenı, kuyruk filtre grubu ve segment sırası
 * BURADAN gelir; istemciler (web + Flutter) `sozluk()` ile alır, kod sabitlemez.
 *
 * NULL (hiç aranmamış) = "Bekliyor" — enum değeri değildir; `metin(null)` bunu karşılar.
 */
enum AramaSonucu: int
{
    case ULASILAMADI = 0;
    case ARANDI = 1;
    case CEVAPSIZ = 2;
    case TEKRAR_ARANACAK = 3;   // arama randevusu (callback)
    case GORUSULDU = 4;
    case MESGUL = 5;
    case ON_GORUSME = 6;
    case TELEFONDA_SATIS = 7;
    case RANDEVU_OLUSTURULDU = 8;
    case IPTAL = 9;

    public const BEKLIYOR_ETIKET = 'Bekliyor';

    /** Eski aranacakDurumMetin ile birebir (mobil bu metni gösterir). */
    public function etiket(): string
    {
        return match ($this) {
            self::ULASILAMADI => 'Ulaşılamadı',
            self::ARANDI => 'Arandı',
            self::CEVAPSIZ => 'Cevapsız',
            self::TEKRAR_ARANACAK => 'Tekrar Aranacak',
            self::GORUSULDU => 'Görüşüldü',
            self::MESGUL => 'Meşgul',
            self::ON_GORUSME => 'Ön Görüşme Randevusu',
            self::TELEFONDA_SATIS => 'Telefonda Satış',
            self::RANDEVU_OLUSTURULDU => 'Randevu Oluşturuldu',
            self::IPTAL => 'İptal Edildi',
        };
    }

    /** Rozet / kart kenarı renk tokenı (eski durStil c alanı). */
    public function renk(): string
    {
        return match ($this) {
            self::GORUSULDU, self::ARANDI, self::TELEFONDA_SATIS => 'yesil',
            self::CEVAPSIZ, self::MESGUL, self::IPTAL => 'kirmizi',
            self::ULASILAMADI => 'turuncu',
            self::TEKRAR_ARANACAK, self::ON_GORUSME, self::RANDEVU_OLUSTURULDU => 'mavi',
        };
    }

    /** Agent'ın "Görüşme Sonucu" formunda doğrudan seçebildiği sonuçlar (eski santral_not_ekle beyaz listesi). */
    public function agentSecebilir(): bool
    {
        return in_array($this, [self::ULASILAMADI, self::CEVAPSIZ, self::GORUSULDU, self::MESGUL, self::ON_GORUSME, self::TELEFONDA_SATIS], true);
    }

    /** Zamanı (tarih+saat) olan ve TAKVİMDE yaşayan sonuçlar: arama randevusu, ön görüşme, satış. */
    public function takvimde(): bool
    {
        return in_array($this, [self::TEKRAR_ARANACAK, self::ON_GORUSME, self::TELEFONDA_SATIS], true);
    }

    /** null / '' / geçersiz → null (hiç aranmamış). */
    public static function koddan(int|string|null $kod): ?self
    {
        if ($kod === null || $kod === '' || ! is_numeric($kod)) {
            return null;
        }

        return self::tryFrom((int) $kod);
    }

    public static function metin(int|string|null $kod): string
    {
        return self::koddan($kod)?->etiket() ?? self::BEKLIYOR_ETIKET;
    }

    public static function renkKodu(int|string|null $kod): string
    {
        return self::koddan($kod)?->renk() ?? 'gri';
    }

    /**
     * Çalışma ekranı kuyruk filtreleri (eski AG_FILTRELER) — kod listesi; 'bekleyen' = NULL.
     * Sıra = ekrandaki çip sırası.
     *
     * @return array<int, array{kod:string, ad:string, sonuclar:array<int,int|null>}>
     */
    public static function kuyrukGruplari(): array
    {
        return [
            ['kod' => 'hepsi', 'ad' => 'Tümü', 'sonuclar' => []],
            ['kod' => 'bekleyen', 'ad' => 'Bekleyen', 'sonuclar' => [null]],
            ['kod' => 'ongorusme', 'ad' => 'Ön Görüşme', 'sonuclar' => [6]],
            ['kod' => 'satis', 'ad' => 'Satış', 'sonuclar' => [7]],
            ['kod' => 'randevuolustu', 'ad' => 'Randevu Oluşturuldu', 'sonuclar' => [8]],
            ['kod' => 'iptal', 'ad' => 'İptal', 'sonuclar' => [9]],
            ['kod' => 'arandi', 'ad' => 'Görüşülen', 'sonuclar' => [1, 4]],
            ['kod' => 'olumsuz', 'ad' => 'Cevapsız', 'sonuclar' => [0, 2, 5]],
            ['kod' => 'randevu', 'ad' => 'Tekrar Aranacak', 'sonuclar' => [3]],
        ];
    }

    /** Performans → personel görüşme dökümü filtre çipleri (eski PD_GRUPLAR). */
    public static function performansGruplari(): array
    {
        return [
            ['kod' => 'hepsi', 'ad' => 'Tümü', 'sonuclar' => []],
            ['kod' => 'ongorusme', 'ad' => 'Ön Görüşme', 'sonuclar' => [6]],
            ['kod' => 'satis', 'ad' => 'Satış', 'sonuclar' => [7]],
            ['kod' => 'gorusuldu', 'ad' => 'Cevaplanan', 'sonuclar' => [1, 4]],
            ['kod' => 'cevapsiz', 'ad' => 'Cevapsız / Meşgul', 'sonuclar' => [2, 5]],
            ['kod' => 'ulasilamadi', 'ad' => 'Ulaşılamadı', 'sonuclar' => [0]],
            ['kod' => 'randevu', 'ad' => 'Tekrar Aranacak', 'sonuclar' => [3]],
        ];
    }

    /**
     * Liste Yönetimi segment seti (eski cagri_liste_segmentler SABİT sırası). 'Arandı' (1)
     * yalnız listede varsa eklenir (segmentler() içinde).
     *
     * @return array<int, string>  segment kodları ('bekleyen' = NULL)
     */
    public static function segmentSirasi(): array
    {
        return ['6', '7', '8', '4', '2', '5', '0', '3', 'bekleyen'];
    }

    /** Segment kodu → etiket ('bekleyen' → Bekleyen). */
    public static function segmentEtiketi(string $kod): string
    {
        return $kod === 'bekleyen' ? 'Bekleyen' : self::metin($kod);
    }

    /** Toplu aktarımın varsayılan seçimi: sonuç alınamayanlar (bekleyen + ulaşılamadı + cevapsız + meşgul). */
    public static function sonucAlinamayanlar(): array
    {
        return ['bekleyen', '0', '2', '5'];
    }

    /** İstemcilerin (web + Flutter) tek seferde aldığı sözlük — UI kod sabitlemez. */
    public static function sozluk(): array
    {
        return [
            'sonuclar' => array_map(fn (self $s) => [
                'kod' => $s->value, 'ad' => $s->etiket(), 'renk' => $s->renk(), 'agent_secebilir' => $s->agentSecebilir(),
            ], self::cases()),
            'bekliyor'        => ['ad' => self::BEKLIYOR_ETIKET, 'renk' => 'gri'],
            'kuyruk_gruplari' => self::kuyrukGruplari(),
        ];
    }
}
