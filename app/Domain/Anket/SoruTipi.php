<?php

namespace App\Domain\Anket;

/**
 * Anket soru tipleri — TEK KAYNAK (saf). Eski blade'de TIP_META (JS), Flutter'da _soruTipleri,
 * müşteri sayfasında if/else olarak üç ayrı kopyaydı; web + Flutter kataloğu API'den okur.
 *
 * Grup: 'skor' istatistik üretir (NPS / CSAT), 'cevap' müşteriden cevap alır, 'yapi' cevapsız eleman.
 */
enum SoruTipi: string
{
    case NPS = 'nps';
    case CSAT_YILDIZ = 'csat_yildiz';
    case EVET_HAYIR = 'evet_hayir';
    case TEK_SECIM = 'tek_secim';
    case COK_SECIM = 'cok_secim';
    case METIN = 'metin';
    case UZUN_METIN = 'uzun_metin';
    case BOLUM_BASLIGI = 'bolum_basligi';
    case BILGI_METNI = 'bilgi_metni';

    public const GRUP_SKOR = 'skor';
    public const GRUP_CEVAP = 'cevap';
    public const GRUP_YAPI = 'yapi';

    private const GRUP_ETIKETLERI = [
        self::GRUP_SKOR  => 'Skor Soruları',
        self::GRUP_CEVAP => 'Cevap Soruları',
        self::GRUP_YAPI  => 'Yapı Elemanları',
    ];

    /** Eski kayıtların tipleri → kanonik karşılık (eski 'csat' müşteri sayfasında hiç çizilmiyordu). */
    private const ESKI_TIPLER = ['csat' => 'csat_yildiz', 'metin_blogu' => 'bilgi_metni'];

    public static function dbden(?string $tip): ?self
    {
        $tip = (string) $tip;

        return self::tryFrom(self::ESKI_TIPLER[$tip] ?? $tip);
    }

    public function etiket(): string
    {
        return match ($this) {
            self::NPS           => 'NPS (0-10)',
            self::CSAT_YILDIZ   => 'Yıldız (1-5)',
            self::EVET_HAYIR    => 'Evet/Hayır',
            self::TEK_SECIM     => 'Tek Seçim',
            self::COK_SECIM     => 'Çok Seçim',
            self::METIN         => 'Kısa Metin',
            self::UZUN_METIN    => 'Uzun Metin',
            self::BOLUM_BASLIGI => 'Bölüm Başlığı',
            self::BILGI_METNI   => 'Bilgi Metni',
        };
    }

    public function grup(): string
    {
        return match ($this) {
            self::NPS, self::CSAT_YILDIZ => self::GRUP_SKOR,
            self::BOLUM_BASLIGI, self::BILGI_METNI => self::GRUP_YAPI,
            default => self::GRUP_CEVAP,
        };
    }

    public function ikon(): string
    {
        return match ($this) {
            self::NPS           => 'bi-graph-up',
            self::CSAT_YILDIZ   => 'bi-star',
            self::EVET_HAYIR    => 'bi-check2-square',
            self::TEK_SECIM     => 'bi-record-circle',
            self::COK_SECIM     => 'bi-ui-checks',
            self::METIN         => 'bi-dash-lg',
            self::UZUN_METIN    => 'bi-text-left',
            self::BOLUM_BASLIGI => 'bi-type-h1',
            self::BILGI_METNI   => 'bi-info-circle',
        };
    }

    public function renk(): string
    {
        return match ($this) {
            self::NPS                       => '#5C008E',
            self::CSAT_YILDIZ               => '#f59e0b',
            self::EVET_HAYIR                => '#0ea5e9',
            self::TEK_SECIM, self::COK_SECIM => '#7c3aed',
            self::METIN, self::UZUN_METIN   => '#10b981',
            self::BOLUM_BASLIGI             => '#475569',
            self::BILGI_METNI               => '#0891b2',
        };
    }

    public function secenekli(): bool
    {
        return $this === self::TEK_SECIM || $this === self::COK_SECIM;
    }

    /** Müşteriden cevap bekler mi (yapı elemanları beklemez, 'zorunlu' anlamsızdır). */
    public function cevapli(): bool
    {
        return $this->grup() !== self::GRUP_YAPI;
    }

    /** Şablon oluşturucu kataloğu: {gruplar[], tipler[]}. */
    public static function katalog(): array
    {
        $gruplar = [];
        foreach (self::GRUP_ETIKETLERI as $grup => $etiket) {
            $gruplar[] = ['grup' => $grup, 'etiket' => $etiket];
        }

        return [
            'gruplar' => $gruplar,
            'tipler'  => array_map(fn (self $t) => [
                'tip'        => $t->value,
                'etiket'     => $t->etiket(),
                'grup'       => $t->grup(),
                'ikon'       => $t->ikon(),
                'renk'       => $t->renk(),
                'secenekli'  => $t->secenekli(),
                'cevapli'    => $t->cevapli(),
            ], self::cases()),
        ];
    }
}
