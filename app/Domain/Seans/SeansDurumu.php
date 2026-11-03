<?php

namespace App\Domain\Seans;

use App\Domain\Randevu\SeansDurumGecisi;

/**
 * Bir seansin (adisyon_paket_seanslar.geldi) durumu — web, Flutter ve rapor icin
 * TEK sozluk. DB degeri: null = beklemede, 1 = geldi, 0 = gelmedi, 2 = telafi.
 *
 * Eski ekranda "Beklemede" secimi seans satirini SILERDI (kullanim geri alinir);
 * v2'de de ayni anlam korunur (bkz. SeansYazServisi::durumGuncelle).
 */
enum SeansDurumu: string
{
    case BEKLEMEDE = 'beklemede';
    case GELDI = 'geldi';
    case GELMEDI = 'gelmedi';
    case TELAFI = 'telafi';

    /** DB/istek degerinden (null|''|0|1|2, string de olabilir) durum. Taninmayan → beklemede. */
    public static function geldidenCoz(mixed $geldi): self
    {
        if ($geldi === null || $geldi === '') {
            return self::BEKLEMEDE;
        }

        return match ((int) $geldi) {
            1 => self::GELDI,
            0 => self::GELMEDI,
            SeansDurumGecisi::TELAFI => self::TELAFI,
            default => self::BEKLEMEDE,
        };
    }

    /** DB'ye yazilacak geldi degeri. */
    public function geldi(): ?int
    {
        return match ($this) {
            self::BEKLEMEDE => null,
            self::GELDI => 1,
            self::GELMEDI => 0,
            self::TELAFI => SeansDurumGecisi::TELAFI,
        };
    }

    public function etiket(): string
    {
        return match ($this) {
            self::BEKLEMEDE => 'Beklemede',
            self::GELDI => 'Geldi',
            self::GELMEDI => 'Gelmedi',
            self::TELAFI => 'Telafi',
        };
    }

    /** Istemcilerin lejant/buton metinleri icin sozluk (UI'da sabit liste tutulmaz). */
    public static function sozluk(): array
    {
        return array_map(fn (self $d) => ['kod' => $d->value, 'geldi' => $d->geldi(), 'etiket' => $d->etiket()], self::cases());
    }
}
