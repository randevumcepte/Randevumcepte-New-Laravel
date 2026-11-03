<?php

namespace App\Domain\Afis;

/**
 * Bir salonun afişine basılacak içerik (değişmez). Görsel üretimden ve veri kaynağından
 * bağımsız — üretici yalnız bunu çizer, servis yalnız bunu doldurur.
 */
final class AfisIcerigi
{
    public readonly string $ad;

    public function __construct(
        public readonly int $salonId,
        ?string $ad,
        public readonly string $indirLinki,
        public readonly ?string $logoDosyasi,   // mutlak dosya yolu; yoksa / okunamıyorsa null
        public readonly int $logoSurumu = 0,    // logo dosyasının mtime'ı (içerik değişince önbellek düşsün)
    ) {
        $this->ad = trim((string) $ad);
    }

    /** Logo yoksa kutuya basılan baş harf (eski: isletme_adi ?: salon_adi ?: 'A'). */
    public function basHarf(): string
    {
        return mb_strtoupper(mb_substr($this->ad !== '' ? $this->ad : 'A', 0, 1, 'UTF-8'), 'UTF-8');
    }

    /** İndirme dosya adı: uygulama-afisi-<ascii-ad>.pdf (eski trSlug + [^A-Za-z0-9_-] → '-'). */
    public function dosyaAdi(string $uzanti): string
    {
        $ascii = strtr($this->ad !== '' ? $this->ad : 'afis', [
            'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I',
            'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U',
        ]);
        $slug = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $ascii), '-');

        return 'uygulama-afisi-'.($slug !== '' ? $slug : 'afis').'.'.$uzanti;
    }

    /**
     * Çıktıyı etkileyen her şeyin özeti — önbellek anahtarı. Logo dosyası değişince
     * (aynı yol, yeni içerik) logoSurumu ile geçersizleşir.
     */
    public function parmakIzi(): string
    {
        return sha1(implode('|', [$this->salonId, $this->ad, $this->indirLinki, $this->logoDosyasi ?? '-', $this->logoSurumu]));
    }
}
