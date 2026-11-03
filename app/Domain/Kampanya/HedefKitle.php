<?php

namespace App\Domain\Kampanya;

use App\Domain\Musteri\Segment;
use App\Exceptions\GecersizIslemException;

/**
 * Kampanya HEDEF KİTLESİ — değer nesnesi (saf). Kampanyada üç ham alan olarak saklanır
 * (kampanya_yonetimi.hedef_filtre / hedef_grup / hedef_cinsiyet; eski sihirbaz girdileri
 * gelenGelmeyenMusteri / musteriGruplari / katilimciTuru ile aynı değerler):
 *
 *   filtre   : ''  tümü · '1'..'5' son N yılda portföye eklenen · '6' sadık · '7' aktif · '8' pasif
 *   grup     : 'haricigrup-{grup_sms.id}' (verilirse filtre yok sayılır; eski 'hastagrup-' hiçbir arayüzde yok)
 *   cinsiyet : '' · 'kadinlar' · 'erkekler'
 *
 * 6/7/8 müşteri listesindeki segment kuralının AYNISI (Domain\Musteri\Segment — salondaki
 * tahsilat satırı sayısı; zaman penceresi yok). Sihirbaz kartları ("preset") form-verisi'nden gelir;
 * açıklamalar gerçek SQL'i anlatır (eski metinler "yakın zamanda / uzun süredir" diyordu, yanlıştı).
 */
final class HedefKitle
{
    /** Sihirbaz kartları: kod => [ad, açıklama, filtre, ikon]. 'grup' kartı grup seçtirir. */
    public const PRESETLER = [
        'tum'     => ['Tüm Müşterilerim', 'Portföyünüzdeki, telefonu kayıtlı tüm müşteriler', '', 'bi-people'],
        'sadik'   => ['Sadık Müşterilerim', 'En az 3 ödeme kaydı olan müşteriler', '6', 'bi-star'],
        'aktif'   => ['Aktif Müşteriler', '1 veya 2 ödeme kaydı olan müşteriler', '7', 'bi-lightning'],
        'pasif'   => ['Geri Kazanılmalı', 'Hiç ödeme kaydı olmayan müşteriler', '8', 'bi-arrow-counterclockwise'],
        'son1yil' => ['Son 1 Yılın Müşterileri', 'Geçen yılın başından beri portföye eklenenler', '1', 'bi-calendar-check'],
        'grup'    => ['Özel Grubum', 'SMS gruplarınızdan birini seçin', null, 'bi-collection'],
    ];

    public const CINSIYETLER = ['' => 'Tümü', 'kadinlar' => 'Kadınlar', 'erkekler' => 'Erkekler'];

    /** users.cinsiyet değeri (eski musteriportfoydropliste: 0 kadın, 1 erkek). */
    private const CINSIYET_DB = ['kadinlar' => 0, 'erkekler' => 1];

    private function __construct(
        public readonly string $filtre,
        public readonly ?string $grupTuru,
        public readonly ?int $grupId,
        public readonly string $cinsiyet,
    ) {}

    /** Ham alanlardan (istek ya da kayıt). Geçersiz değer → 422. */
    public static function olustur(?string $filtre, ?string $grup, ?string $cinsiyet): self
    {
        $filtre = trim((string) $filtre);
        $grup = trim((string) $grup);
        $cinsiyet = trim((string) $cinsiyet);

        $grupTuru = null;
        $grupId = null;
        if ($grup !== '') {
            if (! preg_match('/^(haricigrup)-(\d+)$/', $grup, $m)) {
                throw new GecersizIslemException('Geçersiz müşteri grubu');
            }
            [$grupTuru, $grupId] = [$m[1], (int) $m[2]];
            $filtre = '';
        }
        if ($filtre !== '' && ! in_array($filtre, ['1', '2', '3', '4', '5', '6', '7', '8'], true)) {
            throw new GecersizIslemException('Geçersiz hedef kitle');
        }
        if ($cinsiyet !== '' && ! isset(self::CINSIYET_DB[$cinsiyet])) {
            throw new GecersizIslemException('Geçersiz cinsiyet seçimi');
        }

        return new self($filtre, $grupTuru, $grupId, $cinsiyet);
    }

    /** Kayıttaki hedef_* kolonlarından (eski kayıtta NULL olabilir → tümü). */
    public static function kayittan(object $k): self
    {
        try {
            return self::olustur($k->hedef_filtre ?? '', $k->hedef_grup ?? '', $k->hedef_cinsiyet ?? '');
        } catch (GecersizIslemException) {
            return new self('', null, null, '');
        }
    }

    /** Segment kodu (6/7/8 → SADIK/AKTIF/PASIF), değilse null. */
    public function segment(): ?int
    {
        return match ($this->filtre) {
            '6' => Segment::SADIK,
            '7' => Segment::AKTIF,
            '8' => Segment::PASIF,
            default => null,
        };
    }

    /** '1'..'5' → portföye eklenme alt sınırı (N yıl önceki yılın 1 Ocak'ı; eski kural). */
    public function kayitBaslangici(\DateTimeInterface $simdi): ?string
    {
        if (! in_array($this->filtre, ['1', '2', '3', '4', '5'], true)) {
            return null;
        }
        $yil = (int) $simdi->format('Y') - (int) $this->filtre;

        return sprintf('%04d-01-01 00:00:00', $yil);
    }

    public function cinsiyetDb(): ?int
    {
        return self::CINSIYET_DB[$this->cinsiyet] ?? null;
    }

    public function grupDegeri(): string
    {
        return $this->grupTuru ? $this->grupTuru.'-'.$this->grupId : '';
    }

    /** Sihirbaz kartı kodu (düzenleme prefill'i; eski JS eşlemesi: grup > 6 > 7 > 8 > 1 > tümü). */
    public function presetKodu(): string
    {
        if ($this->grupTuru) {
            return 'grup';
        }
        foreach (self::PRESETLER as $kod => [, , $filtre]) {
            if ($filtre !== null && $filtre === $this->filtre) {
                return $kod;
            }
        }

        return 'tum';
    }

    /**
     * kampanya_yonetimi.musteri_turu etiketi (eski musteriTuru metninin düzgün biçimi):
     *   "Tüm müşteriler" · "Kadın sadık müşteriler" · "Erkek son 2 yıllık müşteriler" · "VIP grubu"
     */
    public function etiket(?string $grupAdi = null): string
    {
        if ($this->grupTuru) {
            return trim(($grupAdi ?: 'Seçili').' grubu');
        }
        $cins = ['kadinlar' => 'Kadın', 'erkekler' => 'Erkek'][$this->cinsiyet] ?? null;
        $seg = match (true) {
            $this->filtre === '6' => 'sadık',
            $this->filtre === '7' => 'aktif',
            $this->filtre === '8' => 'pasif',
            $this->filtre !== ''  => 'son '.$this->filtre.' yıllık',
            default               => null,
        };
        $parca = array_filter([$cins, $seg]);

        return $parca ? self::ilkHarfBuyuk(implode(' ', $parca)).' müşteriler' : 'Tüm müşteriler';
    }

    /** @return array{hedef_filtre:string, hedef_grup:string, hedef_cinsiyet:string} */
    public function kolonlar(): array
    {
        return ['hedef_filtre' => $this->filtre, 'hedef_grup' => $this->grupDegeri(), 'hedef_cinsiyet' => $this->cinsiyet];
    }

    public function esitMi(self $o): bool
    {
        return $this->kolonlar() === $o->kolonlar();
    }

    /** @return array<int, array{kod:string, ad:string, aciklama:string, filtre:?string, ikon:string}> */
    public static function presetSozlugu(): array
    {
        return array_map(fn ($kod, $p) => ['kod' => $kod, 'ad' => $p[0], 'aciklama' => $p[1], 'filtre' => $p[2], 'ikon' => $p[3]],
            array_keys(self::PRESETLER), self::PRESETLER);
    }

    private static function ilkHarfBuyuk(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }
}
