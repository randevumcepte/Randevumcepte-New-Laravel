<?php

namespace App\Domain\SistemYonetim;

/** Destek talebi durumu. Sirasi listedeki varsayilan siralamadir. */
enum TalepDurumu: string
{
    use EtiketliSecenek;

    case Acik = 'acik';
    case Islemde = 'islemde';
    case Bekliyor = 'bekliyor';
    case Cozumlendi = 'cozumlendi';
    case Kapali = 'kapali';

    public const FILTRE_ACIK = 'acik_islemde';

    public const FILTRE_HEPSI = 'hepsi';

    public function etiket(): string
    {
        return match ($this) {
            self::Acik       => 'Açık',
            self::Islemde    => 'İşlemde',
            self::Bekliyor   => 'Bekliyor',
            self::Cozumlendi => 'Çözümlendi',
            self::Kapali     => 'Kapalı',
        };
    }

    public function renk(): string
    {
        return match ($this) {
            self::Acik       => 'danger',
            self::Islemde    => 'warning',
            self::Bekliyor   => 'info',
            self::Cozumlendi => 'success',
            self::Kapali     => 'muted',
        };
    }

    /** "Acik + Islemde" filtresinin kapsami. @return list<string> */
    public static function acikKapsam(): array
    {
        return [self::Acik->value, self::Islemde->value, self::Bekliyor->value];
    }

    /** @return list<array{kod:string,etiket:string}> */
    public static function filtreSecenekleri(): array
    {
        return [
            ['kod' => self::FILTRE_ACIK, 'etiket' => 'Açık + İşlemde'],
            ['kod' => self::FILTRE_HEPSI, 'etiket' => 'Hepsi'],
            ...array_map(fn (self $d) => ['kod' => $d->value, 'etiket' => $d->etiket()], self::cases()),
        ];
    }

    /** @return list<string> */
    public static function filtreKodlari(): array
    {
        return array_column(self::filtreSecenekleri(), 'kod');
    }
}
