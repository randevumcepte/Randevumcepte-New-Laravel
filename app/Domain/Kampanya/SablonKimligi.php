<?php

namespace App\Domain\Kampanya;

use App\Exceptions\GecersizIslemException;

/**
 * Mesaj şablonu kimliği — eski şablon listesindeki önekli değerler (web + Flutter aynı):
 *   "senaryo-{id}" : Senaryo Sihirbazı senaryosu (kampanya_senaryolari)
 *   "sablon-{id}"  : salonun kendi SMS taslağı (sms_taslaklari)
 *   "{id}"         : sistem kampanya şablonu (kampanya_sablonlari, global)
 */
final class SablonKimligi
{
    public const SENARYO = 'senaryo';

    public const TASLAK = 'sablon';

    public const SISTEM = 'sistem';

    private function __construct(public readonly string $tur, public readonly int $id) {}

    public static function coz(?string $deger): ?self
    {
        $deger = trim((string) $deger);
        if ($deger === '') {
            return null;
        }
        if (ctype_digit($deger)) {
            return new self(self::SISTEM, (int) $deger);
        }
        if (! preg_match('/^(senaryo|sablon)-(\d+)$/', $deger, $m)) {
            throw new GecersizIslemException('Geçersiz mesaj şablonu');
        }

        return new self($m[1], (int) $m[2]);
    }

    public function deger(): string
    {
        return $this->tur === self::SISTEM ? (string) $this->id : $this->tur.'-'.$this->id;
    }
}
