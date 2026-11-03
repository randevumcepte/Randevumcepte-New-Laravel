<?php

namespace App\Domain\Randevu;

use App\Domain\Rapor\RaporDonemi;
use App\Exceptions\GecersizIslemException;

/**
 * Randevu listesi "Zaman" filtresi — eski randevular_liste.blade secenekleriyle ayni
 * (Tum Zamanlar, Bugun, Yarin, Bu ay, Onumuzdeki ay, Bu yil, Onumuzdeki yil, Ozel).
 * Rapor donemlerinden farki ileriye donuk secenekler + "tumu" (tarih filtresi yok).
 * Ortak anahtarlar (bugun / ay / yil / ozel) RaporDonemi'ne devredilir — tarih
 * dogrulamasi ve cevap blogu (dizi) tek kaynaktan. Web ve Flutter ayni parametreleri
 * gonderir: donem, baslangic, bitis (ozel).
 */
final class RandevuZamani
{
    public const SECENEKLER = [
        'tumu'        => 'Tüm Zamanlar',
        'bugun'       => 'Bugün',
        'yarin'       => 'Yarın',
        'ay'          => 'Bu Ay',
        'gelecek_ay'  => 'Önümüzdeki Ay',
        'yil'         => 'Bu Yıl',
        'gelecek_yil' => 'Önümüzdeki Yıl',
        'ozel'        => 'Özel Aralık',
    ];

    public const VARSAYILAN = 'bugun';

    /**
     * @return ?array{donem:string, etiket:string, baslangic:string, bitis:string, tek_gun:bool}  null = tum zamanlar
     */
    public static function coz(?string $donem, ?string $baslangic, ?string $bitis, ?string $bugun = null): ?array
    {
        $donem = trim((string) $donem) ?: self::VARSAYILAN;
        if (! isset(self::SECENEKLER[$donem])) {
            throw new GecersizIslemException('donem şunlardan biri olmalı: '.implode(', ', array_keys(self::SECENEKLER)));
        }
        if ($donem === 'tumu') {
            return null;
        }

        $g = new \DateTimeImmutable($bugun ?? 'today');
        $ileri = match ($donem) {
            'yarin'       => [$g->modify('+1 day'), $g->modify('+1 day')],
            'gelecek_ay'  => [$g->modify('first day of next month'), $g->modify('last day of next month')],
            'gelecek_yil' => [$g->setDate((int) $g->format('Y') + 1, 1, 1), $g->setDate((int) $g->format('Y') + 1, 12, 31)],
            default       => null,
        };

        // Ileri donemler 'ozel' aralik olarak RaporDonemi'nden gecer (ayni dogrulama + blok).
        $d = $ileri
            ? RaporDonemi::coz('ozel', $ileri[0]->format('Y-m-d'), $ileri[1]->format('Y-m-d'))->dizi()
            : RaporDonemi::coz($donem, $baslangic, $bitis, self::VARSAYILAN, $bugun)->dizi();

        return ['donem' => $donem, 'etiket' => self::SECENEKLER[$donem]] + $d;
    }

    /** API secenek listesi (donem secicisi). */
    public static function secenekListesi(): array
    {
        return array_map(fn ($kod, $ad) => ['kod' => $kod, 'ad' => $ad], array_keys(self::SECENEKLER), self::SECENEKLER);
    }
}
