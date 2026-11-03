<?php

namespace App\Domain\CagriMerkezi;

/**
 * Arama listesi oluşturma FİLTRESİ — normalize edilmiş, değişmez değer nesnesi (DB'siz).
 * Eski App\Services\AramaFiltreService::parseRequest + filtre anahtarları; sorgu kurulumu
 * Services\CagriMerkezi\AramaFiltreSorgusu'nda. Önizleme sayacı, müşteri listesi ve listeye
 * eklenen küme AYNI filtreden üretilir (tek doğruluk kaynağı); filtre_snapshot olarak saklanır.
 *
 * Alanlar (boş = uygulanmaz):
 *   kayit: son1yil | ozel (kayit_t1/kayit_t2 Y-m-d) · durum: pasif | aktif | sadik (v2 Musteri\Segment kuralı)
 *   gelmeyen: 15|30|60|90 gün (son GELDİĞİ randevu) · satis: var | yok (adisyon) · cinsiyet: 0 kadın | 1 erkek
 *   dogumgunu_yaklasan: 1..366 gün · kara_liste_haric · whatsapp_onay · hic_randevu_yok · iptal_eden
 *   il_id / ilce_id · ara (ad / telefon)
 */
final class AramaFiltresi
{
    public const KAYIT = ['son1yil' => 'Son 1 yılda eklenen', 'ozel' => 'Özel tarih aralığı'];

    public const DURUM = ['sadik' => 'Sadık (3+ işlem)', 'aktif' => 'Aktif (1-2 işlem)', 'pasif' => 'Pasif (hiç işlem yok)'];

    public const GELMEYEN = [15, 30, 60, 90];

    public const SATIS = ['var' => 'Satış yapılmış', 'yok' => 'Satış yapılmamış'];

    public const CINSIYET = [0 => 'Kadın', 1 => 'Erkek'];

    public const BAYRAKLAR = [
        'kara_liste_haric' => 'Kara liste hariç',
        'whatsapp_onay'    => 'WhatsApp onaylı',
        'hic_randevu_yok'  => 'Hiç randevu almamış',
        'iptal_eden'       => 'Randevu iptal eden',
    ];

    /** @param array<string,bool> $bayraklar */
    private function __construct(
        public readonly ?string $kayit,
        public readonly ?string $kayitT1,
        public readonly ?string $kayitT2,
        public readonly ?string $durum,
        public readonly ?int $gelmeyen,
        public readonly ?string $satis,
        public readonly ?int $cinsiyet,
        public readonly ?int $dogumgunuYaklasan,
        public readonly array $bayraklar,
        public readonly ?int $ilId,
        public readonly ?int $ilceId,
        public readonly ?string $ara,
    ) {}

    /** Ham istek dizisinden (bilinmeyen / geçersiz değerler sessizce atılır). */
    public static function diziden(array $f): self
    {
        $secim = fn ($v, array $izin) => in_array((string) $v, array_map('strval', $izin), true) ? (string) $v : null;
        $tarih = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $pozitif = fn ($v, int $max) => is_numeric($v) && (int) $v > 0 ? min((int) $v, $max) : null;
        $kayit = $secim($f['kayit'] ?? null, array_keys(self::KAYIT));
        $cinsiyet = $secim($f['cinsiyet'] ?? null, array_keys(self::CINSIYET));

        return new self(
            kayit: $kayit,
            kayitT1: $kayit === 'ozel' ? $tarih($f['kayit_t1'] ?? null) : null,
            kayitT2: $kayit === 'ozel' ? $tarih($f['kayit_t2'] ?? null) : null,
            durum: $secim($f['durum'] ?? null, array_keys(self::DURUM)),
            gelmeyen: ($g = $secim($f['gelmeyen'] ?? null, self::GELMEYEN)) !== null ? (int) $g : null,
            satis: $secim($f['satis'] ?? null, array_keys(self::SATIS)),
            cinsiyet: $cinsiyet !== null ? (int) $cinsiyet : null,
            dogumgunuYaklasan: $pozitif($f['dogumgunu_yaklasan'] ?? null, 366),
            bayraklar: array_map(fn ($k) => filter_var($f[$k] ?? false, FILTER_VALIDATE_BOOLEAN), array_combine(array_keys(self::BAYRAKLAR), array_keys(self::BAYRAKLAR))),
            ilId: $pozitif($f['il_id'] ?? null, PHP_INT_MAX),
            ilceId: $pozitif($f['ilce_id'] ?? null, PHP_INT_MAX),
            ara: ($a = trim((string) ($f['ara'] ?? $f['search'] ?? ''))) !== '' ? mb_substr($a, 0, 100) : null,
        );
    }

    public function bayrak(string $ad): bool
    {
        return (bool) ($this->bayraklar[$ad] ?? false);
    }

    /** Aynı filtre, metin araması olmadan (listeye eklenecek küme aramadan bağımsızdır). */
    public function aramasiz(): self
    {
        return new self($this->kayit, $this->kayitT1, $this->kayitT2, $this->durum, $this->gelmeyen, $this->satis,
            $this->cinsiyet, $this->dogumgunuYaklasan, $this->bayraklar, $this->ilId, $this->ilceId, null);
    }

    /** arama_listesi.filtre_snapshot (eski anahtarlarla uyumlu; boşlar atılır). */
    public function snapshot(): array
    {
        return array_filter([
            'kayit' => $this->kayit, 'kayit_t1' => $this->kayitT1, 'kayit_t2' => $this->kayitT2, 'durum' => $this->durum,
            'gelmeyen' => $this->gelmeyen, 'satis' => $this->satis, 'cinsiyet' => $this->cinsiyet,
            'dogumgunu_yaklasan' => $this->dogumgunuYaklasan, 'il_id' => $this->ilId, 'ilce_id' => $this->ilceId,
        ] + array_filter($this->bayraklar), fn ($v) => $v !== null && $v !== false);
    }

    /** Okunur özet ("Sadık (3+ işlem) · 30 gündür gelmeyen · Kara liste hariç"). */
    public function ozet(): string
    {
        $p = array_filter([
            $this->kayit === 'ozel' ? 'Kayıt '.($this->kayitT1 ?? '…').' – '.($this->kayitT2 ?? '…') : ($this->kayit ? self::KAYIT[$this->kayit] : null),
            $this->durum ? self::DURUM[$this->durum] : null,
            $this->gelmeyen ? $this->gelmeyen.' gündür gelmeyen' : null,
            $this->satis ? self::SATIS[$this->satis] : null,
            $this->cinsiyet !== null ? self::CINSIYET[$this->cinsiyet] : null,
            $this->dogumgunuYaklasan ? 'Doğum günü '.$this->dogumgunuYaklasan.' gün içinde' : null,
        ]);
        foreach (self::BAYRAKLAR as $k => $ad) {
            if ($this->bayrak($k)) {
                $p[] = $ad;
            }
        }

        return $p ? implode(' · ', $p) : 'Tüm müşteriler';
    }

    /** Form seçenekleri (web + Flutter aynı listeyi çizer). */
    public static function secenekler(): array
    {
        $liste = fn (array $m) => array_map(fn ($k, $ad) => ['kod' => (string) $k, 'ad' => $ad], array_keys($m), $m);

        return [
            'kayit'     => $liste(self::KAYIT),
            'durum'     => $liste(self::DURUM),
            'gelmeyen'  => array_map(fn ($g) => ['kod' => (string) $g, 'ad' => "Son $g gündür gelmeyen"], self::GELMEYEN),
            'satis'     => $liste(self::SATIS),
            'cinsiyet'  => $liste(self::CINSIYET),
            'bayraklar' => array_map(fn ($k, $ad) => ['kod' => $k, 'ad' => $ad, 'varsayilan' => $k === 'kara_liste_haric'], array_keys(self::BAYRAKLAR), self::BAYRAKLAR),
        ];
    }
}
