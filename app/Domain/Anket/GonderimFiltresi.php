<?php

namespace App\Domain\Anket;

/** Gönderim listesi filtresi — değerler mobil v1 `filtre` parametresiyle aynı. */
enum GonderimFiltresi: string
{
    case TUM = 'tum';
    case CEVAPLI = 'cevapli';
    case GOOGLE_TIKLAYAN = 'google_tiklayan';
    case KOTU_PUAN = 'kotu_puan';

    public static function dbden(?string $v): self
    {
        return self::tryFrom((string) $v) ?? self::TUM;
    }

    public function etiket(): string
    {
        return match ($this) {
            self::TUM             => 'Tümü',
            self::CEVAPLI         => 'Cevaplananlar',
            self::GOOGLE_TIKLAYAN => "Google'a Yönlendirilen",
            self::KOTU_PUAN       => 'Düşük Puan Veren',
        };
    }

    public static function secenekler(): array
    {
        return array_map(fn (self $f) => ['kod' => $f->value, 'ad' => $f->etiket()], self::cases());
    }
}
