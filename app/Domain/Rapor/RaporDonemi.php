<?php

namespace App\Domain\Rapor;

use App\Exceptions\GecersizIslemException;

/**
 * Rapor donemi (tarih araligi) — TUM rapor uclarinin (Satis Raporlari, Isletme
 * Raporlari, Kasa, Gun Sonu) TEK donem sozlesmesi. Web ve Flutter ayni parametreleri
 * gonderir:
 *
 *   donem     : bugun | dun | hafta | ay | gecen_ay | yil | gecen_yil | ozel
 *               | son7 | son30 | son90 (KAYAN: bugun dahil geriye N gun — Ozet kartlari)
 *   baslangic : Y-m-d (yalniz donem=ozel)
 *   bitis     : Y-m-d (yalniz donem=ozel; bos → baslangic)
 *
 * Eski uc ayri ayristirici yerine (isletme raporlari 'period/bas/bit', satis raporlari
 * 'zaman="Y-m-d / Y-m-d"', kasa 'baslangic_bitis_tarihi') — gecersiz deger 500 yerine 422.
 * "ay" / "yil" takvim donemidir (eski secenek degerleriyle ayni: ayin/yilin sonuna kadar).
 */
final class RaporDonemi
{
    public const SECENEKLER = [
        'bugun'     => 'Bugün',
        'dun'       => 'Dün',
        'hafta'     => 'Bu Hafta',
        'ay'        => 'Bu Ay',
        'gecen_ay'  => 'Geçen Ay',
        'yil'       => 'Bu Yıl',
        'gecen_yil' => 'Geçen Yıl',
        'ozel'      => 'Özel Aralık',
    ];

    /**
     * Kayan donemler (bugun dahil geriye N gun) — eski Ozet kartlarinin "Son 7 / 30 / 90 gün"
     * secenekleri. Rapor ekranlarinin varsayilan listesinde YOK (secenekListesi alt-kume alir).
     */
    public const KAYAN = ['son7' => 7, 'son30' => 30, 'son90' => 90];

    private const KAYAN_ETIKET = ['son7' => 'Son 7 Gün', 'son30' => 'Son 30 Gün', 'son90' => 'Son 90 Gün'];

    private function __construct(
        public readonly string $anahtar,
        public readonly string $baslangic,
        public readonly string $bitis,
    ) {}

    /**
     * @param  string  $varsayilan  donem bos gelirse kullanilacak anahtar
     * @param  ?string $bugun       test icin sabit gun (Y-m-d)
     *
     * @throws GecersizIslemException
     */
    public static function coz(?string $donem, ?string $baslangic, ?string $bitis, string $varsayilan = 'ay', ?string $bugun = null): self
    {
        $donem = trim((string) $donem) ?: $varsayilan;
        if (! isset(self::etiketler()[$donem])) {
            throw new GecersizIslemException('donem şunlardan biri olmalı: '.implode(', ', array_keys(self::etiketler())));
        }
        $g = new \DateTimeImmutable($bugun ?? 'today');

        if (isset(self::KAYAN[$donem])) {
            return new self($donem, $g->modify('-'.(self::KAYAN[$donem] - 1).' days')->format('Y-m-d'), $g->format('Y-m-d'));
        }

        if ($donem === 'ozel') {
            $bas = self::tarih($baslangic, 'baslangic');
            $bit = trim((string) $bitis) === '' ? $bas : self::tarih($bitis, 'bitis');
            if ($bit < $bas) {
                [$bas, $bit] = [$bit, $bas];
            }

            return new self('ozel', $bas, $bit);
        }

        [$bas, $bit] = match ($donem) {
            'bugun'     => [$g, $g],
            'dun'       => [$g->modify('-1 day'), $g->modify('-1 day')],
            'hafta'     => [$g->modify('monday this week'), $g->modify('sunday this week')],
            'ay'        => [$g->modify('first day of this month'), $g->modify('last day of this month')],
            'gecen_ay'  => [$g->modify('first day of last month'), $g->modify('last day of last month')],
            'yil'       => [$g->setDate((int) $g->format('Y'), 1, 1), $g->setDate((int) $g->format('Y'), 12, 31)],
            'gecen_yil' => [$g->setDate((int) $g->format('Y') - 1, 1, 1), $g->setDate((int) $g->format('Y') - 1, 12, 31)],
        };

        return new self($donem, $bas->format('Y-m-d'), $bit->format('Y-m-d'));
    }

    /** Gun-kapsamli DATETIME alt sinir. */
    public function basSaat(): string
    {
        return $this->baslangic.' 00:00:00';
    }

    /** Gun-kapsamli DATETIME ust sinir. */
    public function bitSaat(): string
    {
        return $this->bitis.' 23:59:59';
    }

    public function tekGun(): bool
    {
        return $this->baslangic === $this->bitis;
    }

    /** Cevaplara eklenen donem blogu (UI etiketi + tarih alanlarini doldurmak icin). */
    public function dizi(): array
    {
        return [
            'donem'     => $this->anahtar,
            'etiket'    => self::etiketler()[$this->anahtar],
            'baslangic' => $this->baslangic,
            'bitis'     => $this->bitis,
            'tek_gun'   => $this->tekGun(),
        ];
    }

    /**
     * Ayni uzunlukta komsu donem: $adim=-1 onceki, -6 alti donem once. Takvim donemleri
     * birim kaydirir (gun / hafta / ay / yil — ay tasmasi YOK: 31 Mart'ta "onceki ay" Subat'tir),
     * kayan ve ozel donemler gun sayisi kadar kayar. Sonuc anahtari 'ozel'dir.
     */
    public function kaydir(int $adim): self
    {
        $bas = new \DateTimeImmutable($this->baslangic);
        [$yeniBas, $yeniBit] = match ($this->anahtar) {
            'bugun', 'dun' => [$bas->modify("{$adim} day"), null],
            'hafta'        => [$bas->modify(($adim * 7).' day'), null],
            'ay', 'gecen_ay' => [$b = $bas->modify("first day of {$adim} month"), $b->modify('last day of this month')],
            'yil', 'gecen_yil' => [$b = $bas->setDate((int) $bas->format('Y') + $adim, 1, 1), $b->setDate((int) $b->format('Y'), 12, 31)],
            default        => [$bas->modify(($adim * $this->gunSayisi()).' day'), null],
        };
        $yeniBit ??= $yeniBas->modify('+'.($this->gunSayisi() - 1).' day');

        return new self('ozel', $yeniBas->format('Y-m-d'), $yeniBit->format('Y-m-d'));
    }

    /** Onceki donemin kullanici etiketi (karsilastirma kartlari). */
    public function oncekiEtiket(): string
    {
        return match ($this->anahtar) {
            'bugun'  => 'Dün',
            'hafta'  => 'Geçen Hafta',
            'ay'     => 'Geçen Ay',
            'yil'    => 'Geçen Yıl',
            default  => 'Önceki '.$this->gunSayisi().' Gün',
        };
    }

    /** Gun sayisi (kapsayici). */
    public function gunSayisi(): int
    {
        return (int) (new \DateTimeImmutable($this->baslangic))->diff(new \DateTimeImmutable($this->bitis))->days + 1;
    }

    /**
     * Secenek listesi (UI sabit liste tutmaz): [{kod, ad}]. $kodlar verilirse o sirayla
     * alt-kume (ornegin Ozet kartlari: ['bugun','son7','son30']); yoksa rapor ekranlari listesi.
     *
     * @param  ?array<string>  $kodlar
     */
    public static function secenekListesi(?array $kodlar = null): array
    {
        $ad = self::etiketler();
        $kodlar ??= array_keys(self::SECENEKLER);

        return array_values(array_map(fn ($kod) => ['kod' => $kod, 'ad' => $ad[$kod]], array_filter($kodlar, fn ($k) => isset($ad[$k]))));
    }

    /** @return array<string,string> tum gecerli donemler (takvim + kayan) */
    private static function etiketler(): array
    {
        return self::SECENEKLER + self::KAYAN_ETIKET;
    }

    private static function tarih(?string $v, string $alan): string
    {
        $v = trim((string) $v);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (! $d || $d->format('Y-m-d') !== $v) {
            throw new GecersizIslemException($alan.' Y-m-d biçiminde olmalı');
        }

        return $v;
    }
}
