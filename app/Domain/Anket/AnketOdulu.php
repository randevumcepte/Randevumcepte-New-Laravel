<?php

namespace App\Domain\Anket;

/**
 * Anket ödülü (kupon / puan) — SAF, TEK KAYNAK. Aynı kural eskide dört yerde kopyaydı (web şablon, mobil
 * şablon, web Google ayarı, mobil Google ayarı). İki kolon ailesi aynı biçimdedir:
 *   ONEK_SABLON  'odul_'         anket_sablonlari  → anketi tamamlayana
 *   ONEK_GOOGLE  'google_odul_'  salonlar          → "Google'da Yorum Yaz"a tıklayana (hizmet kapsamı yok)
 *
 * Yalnız seçili tipin alanları dolu kalır; diğerleri null'lanır (eskiyle aynı). Ödülü müşteriye TANIMLAMA
 * eski monolitte (/anket-kaydet, /anket-google-tiklandi) kalır; burada yalnız ayar.
 */
final class AnketOdulu
{
    public const ONEK_SABLON = 'odul_';
    public const ONEK_GOOGLE = 'google_odul_';

    public const TIPLER = ['yok' => 'Ödül yok', 'kupon' => 'İndirim Kuponu', 'puan' => 'Sadakat Puanı'];
    public const INDIRIM_TIPLERI = ['yuzde' => 'Yüzde (%)', 'tutar' => 'Tutar (₺)'];
    public const VARSAYILAN_GECERLILIK_GUN = 30;

    private function __construct(
        public readonly string $tip,
        public readonly ?string $indirimTipi,
        public readonly ?float $deger,
        public readonly ?int $gecerlilikGun,
        public readonly ?int $hizmetId,
        public readonly ?float $puan,
        public readonly ?string $baslik,
    ) {}

    /** Doğrulanmış istek alanlarından (ad = kolon adı) — seçili olmayan tipin alanları atılır. */
    public static function girdiden(array $g, string $onek): self
    {
        $tip = in_array($g[$onek.'tipi'] ?? null, ['kupon', 'puan'], true) ? $g[$onek.'tipi'] : 'yok';
        $baslik = trim((string) ($g[$onek.'baslik'] ?? '')) ?: null;

        return match ($tip) {
            'kupon' => new self(
                'kupon',
                ($g[$onek.'kupon_indirim_tipi'] ?? null) === 'tutar' ? 'tutar' : 'yuzde',
                max(0.0, (float) ($g[$onek.'kupon_deger'] ?? 0)),
                ((int) ($g[$onek.'kupon_gecerlilik_gun'] ?? 0)) ?: null, // 0 / boş = süresiz
                ((int) ($g[$onek.'kupon_hizmet_id'] ?? 0)) ?: null,      // null = tüm hizmetler
                null,
                $baslik,
            ),
            'puan' => new self('puan', null, null, null, null, max(0.0, (float) ($g[$onek.'puan'] ?? 0)), $baslik),
            default => new self('yok', null, null, null, null, null, null),
        };
    }

    /** DB satırından (anket_sablonlari ya da salonlar). */
    public static function satirdan(object $r, string $onek): self
    {
        $s = fn (string $a) => $r->{$onek.$a} ?? null;

        return new self(
            in_array($s('tipi'), ['kupon', 'puan'], true) ? $s('tipi') : 'yok',
            $s('kupon_indirim_tipi'),
            $s('kupon_deger') === null ? null : (float) $s('kupon_deger'),
            $s('kupon_gecerlilik_gun') === null ? null : (int) $s('kupon_gecerlilik_gun'),
            $s('kupon_hizmet_id') === null ? null : (int) $s('kupon_hizmet_id'),
            $s('puan') === null ? null : (float) $s('puan'),
            $s('baslik'),
        );
    }

    /** Hem DB kolonları hem API alanları: {önek}tipi, {önek}kupon_deger ... */
    public function kolonlar(string $onek): array
    {
        $k = [
            $onek.'tipi'                 => $this->tip,
            $onek.'kupon_indirim_tipi'   => $this->indirimTipi,
            $onek.'kupon_deger'          => $this->deger,
            $onek.'kupon_gecerlilik_gun' => $this->gecerlilikGun,
            $onek.'puan'                 => $this->puan,
            $onek.'baslik'               => $this->baslik,
        ];
        if ($onek === self::ONEK_SABLON) {
            $k[$onek.'kupon_hizmet_id'] = $this->hizmetId;
        }

        return $k;
    }

    /** Liste rozeti: "%10 Kupon" | "150 ₺ Kupon" | "50 Puan" | null. */
    public function kisaEtiket(): ?string
    {
        return match ($this->tip) {
            'kupon' => ($this->indirimTipi === 'tutar' ? self::sayi((float) $this->deger).' ₺' : '%'.(int) $this->deger).' Kupon',
            'puan'  => self::sayi((float) $this->puan).' Puan',
            default => null,
        };
    }

    /** 10 → "10", 10.5 → "10,5", 1000 → "1.000" */
    private static function sayi(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }

    public static function tipSecenekleri(): array
    {
        return self::secenek(self::TIPLER);
    }

    public static function indirimSecenekleri(): array
    {
        return self::secenek(self::INDIRIM_TIPLERI);
    }

    private static function secenek(array $sozluk): array
    {
        return array_map(fn ($kod, $ad) => ['kod' => $kod, 'ad' => $ad], array_keys($sozluk), $sozluk);
    }
}
