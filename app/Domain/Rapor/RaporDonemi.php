<?php

namespace App\Domain\Rapor;

use App\Exceptions\GecersizIslemException;

/**
 * Rapor donemi (tarih araligi) — TUM rapor uclarinin (Satis Raporlari, Isletme
 * Raporlari, Kasa, Gun Sonu) TEK donem sozlesmesi. Web ve Flutter ayni parametreleri
 * gonderir:
 *
 *   donem     : bugun | dun | hafta | ay | gecen_ay | yil | gecen_yil | ozel
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
        if (! array_key_exists($donem, self::SECENEKLER)) {
            throw new GecersizIslemException('donem şunlardan biri olmalı: '.implode(', ', array_keys(self::SECENEKLER)));
        }
        $g = new \DateTimeImmutable($bugun ?? 'today');

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
            'etiket'    => self::SECENEKLER[$this->anahtar],
            'baslangic' => $this->baslangic,
            'bitis'     => $this->bitis,
            'tek_gun'   => $this->tekGun(),
        ];
    }

    /** Secenek listesi (UI sabit liste tutmaz): [{kod, ad}]. */
    public static function secenekListesi(): array
    {
        return array_map(fn ($kod, $ad) => ['kod' => $kod, 'ad' => $ad], array_keys(self::SECENEKLER), self::SECENEKLER);
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
