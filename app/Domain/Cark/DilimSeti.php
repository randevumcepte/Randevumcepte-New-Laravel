<?php

namespace App\Domain\Cark;

use InvalidArgumentException;

/**
 * Kaydedilecek çark dilimlerinin TEK doğrulama + normalleştirme kuralı (saf, DB'siz).
 *
 * Çark "garantili hediye" kurgusundadır: salon sahibi TAM OLARAK BİR dilimi kazanan seçer
 * (olasılık 100), diğerleri 0 — müşteri ne çevirirse çevirsin o dilim çıkar. Eski web ve
 * mobil bu kuralı ayrı ayrı uyguluyordu; burada tek yerde.
 *
 * Girdi satırı (web + Flutter aynı alanlar): {name, color, probability, tip, deger, indirim_tipi}
 *   - tip bilinmiyorsa 'bos'; değersiz tiplerde deger null; indirim birimi yalnız indirimde 'tutar' olabilir
 *   - kupon_mu tipten türetilir (istemci göndermez)
 */
final class DilimSeti
{
    /**
     * @param  array<int, array<string, mixed>>  $dilimler
     * @param  string[]  $renkler  renk gelmezse sırayla kullanılacak palet
     * @return array<int, array{name:string, color:string, probability:int, tip:string, deger:?float, indirim_tipi:string, kupon_mu:int, sira:int}>
     *
     * @throws InvalidArgumentException
     */
    public static function olustur(array $dilimler, int $min, int $max, array $renkler): array
    {
        $dilimler = array_values($dilimler);
        if (count($dilimler) < $min) {
            throw new InvalidArgumentException("En az {$min} dilim olmalıdır.");
        }
        if (count($dilimler) > $max) {
            throw new InvalidArgumentException("En fazla {$max} dilim eklenebilir.");
        }

        $sonuc = [];
        foreach ($dilimler as $i => $d) {
            $olasilik = (int) round((float) str_replace(',', '.', (string) ($d['probability'] ?? 0)));
            if ($olasilik < 0 || $olasilik > 100) {
                throw new InvalidArgumentException('Geçersiz olasılık değeri; 0-100 arası tam sayı olmalıdır.');
            }
            $tip = DilimTipi::gecerli($d['tip'] ?? null);
            $deger = DilimTipi::degerliMi($tip) && isset($d['deger']) && $d['deger'] !== '' ? (float) $d['deger'] : null;
            if ($deger !== null && $deger < 0) {
                throw new InvalidArgumentException('Dilim değeri negatif olamaz.');
            }
            $ad = trim((string) ($d['name'] ?? ''));

            $sonuc[] = [
                'name'         => mb_substr($ad !== '' ? $ad : 'Ödül '.($i + 1), 0, 100),
                'color'        => self::renk($d['color'] ?? null) ?? $renkler[$i % max(1, count($renkler))] ?? '#6c5ce7',
                'probability'  => $olasilik,
                'tip'          => $tip,
                'deger'        => $deger,
                'indirim_tipi' => DilimTipi::birim($tip, $d['indirim_tipi'] ?? null),
                'kupon_mu'     => DilimTipi::indirimMi($tip) ? 1 : 0,
                'sira'         => $i + 1,
            ];
        }

        $kazananlar = count(array_filter($sonuc, fn ($d) => $d['probability'] === 100));
        if ($kazananlar !== 1 || array_sum(array_column($sonuc, 'probability')) !== 100) {
            throw new InvalidArgumentException('Lütfen tam olarak bir dilimi kazanan olarak seçin.');
        }

        return $sonuc;
    }

    /** #RGB / #RRGGBB değilse null (HTML/CSS enjeksiyonu yok). */
    private static function renk(mixed $v): ?string
    {
        $v = trim((string) $v);

        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v) ? $v : null;
    }
}
