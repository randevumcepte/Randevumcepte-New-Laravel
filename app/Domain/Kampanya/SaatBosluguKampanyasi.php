<?php

namespace App\Domain\Kampanya;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Saat boşluğu kampanyası: `kampanyalar` satırındaki `[gap:KEY]` etiketi, indirim ve saat metnini
 * okur ve yeni kampanyanın metinlerini üretir (saf, DB'siz). Kurallar eski
 * aktifGapKampanyalari, randevuKampanyaKontrol ve saatBosluguKampanyaOlustur ile aynıdır.
 */
final class SaatBosluguKampanyasi
{
    public const ETIKET_ONEKI = '[gap:';

    public const GECERLILIK_GUN = 7;

    public const SMS_UST_SINIR = 459;

    private const BOSLUKLAR = [
        'morning'   => ['ad' => 'Sabah',         'bas' => 9,  'bit' => 12, 'sira' => 1],
        'afternoon' => ['ad' => 'Öğleden sonra', 'bas' => 12, 'bit' => 17, 'sira' => 2],
        'evening'   => ['ad' => 'Akşam',         'bas' => 17, 'bit' => 20, 'sira' => 3],
    ];

    /** @return array<string, mixed>|null */
    public static function satirdan(object $k, DateTimeInterface $simdi): ?array
    {
        if (! preg_match('/\[gap:([a-z]+)\]/', (string) $k->kampanya_detay, $m) || ! isset(self::BOSLUKLAR[$m[1]])) {
            return null;
        }
        $bosluk = self::BOSLUKLAR[$m[1]];
        $aciklama = (string) $k->kampanya_aciklama;
        [$bas, $bit] = preg_match('/\((\d{2}):\d{2}-(\d{2}):\d{2}\)/', $aciklama, $s)
            ? [(int) $s[1], (int) $s[2]]
            : [$bosluk['bas'], $bosluk['bit']];

        return [
            'id'          => (int) $k->id,
            'gapKey'      => $m[1],
            'gapLabel'    => $bosluk['ad'],
            'startHour'   => $bas,
            'endHour'     => $bit,
            'discount'    => self::indirim($aciklama, (string) $k->kampanya_baslik),
            'baslik'      => $k->kampanya_baslik,
            'bitisTarihi' => $k->kampanya_bitis_tarihi,
            'kalanGun'    => self::kalanGun((string) $k->kampanya_bitis_tarihi, $simdi),
            'order'       => $bosluk['sira'],
        ];
    }

    public static function gecerliMi(string $anahtar): bool
    {
        return isset(self::BOSLUKLAR[$anahtar]);
    }

    /** Saatin düştüğü boşluk (12 öncesi sabah, 17 öncesi öğleden sonra, sonrası akşam). */
    public static function saattenAnahtar(int $saat): string
    {
        return $saat < 12 ? 'morning' : ($saat < 17 ? 'afternoon' : 'evening');
    }

    public static function etiket(string $anahtar): string
    {
        return self::ETIKET_ONEKI.$anahtar.']';
    }

    public static function etiketliMi(?string $detay): bool
    {
        return str_contains((string) $detay, self::ETIKET_ONEKI);
    }

    public static function ad(string $anahtar): string
    {
        return self::BOSLUKLAR[$anahtar]['ad'];
    }

    public static function baslik(string $ad, int $indirim): string
    {
        return sprintf('%s Fırsatı - %%%d İndirim', $ad, $indirim);
    }

    public static function aciklama(string $ad, int $bas, int $bit, int $indirim): string
    {
        return sprintf(
            'Hafta içi %s saatleri (%02d:00-%02d:00) arasında tüm hizmetlerde %%%d indirim.',
            mb_strtolower($ad, 'UTF-8'), $bas, $bit, $indirim
        );
    }

    public static function detay(string $aciklama, string $baslangic, string $bitis, string $anahtar): string
    {
        return sprintf(
            'Bu kampanya dashboard saat yoğunluğu analizine göre otomatik öneri olarak oluşturuldu. %s Geçerlilik: %s - %s. %s',
            $aciklama, $baslangic, $bitis, self::etiket($anahtar)
        );
    }

    public static function smsMetni(string $salonAdi, string $baslik, string $aciklama): string
    {
        return mb_substr(sprintf('%s - %s %s', $salonAdi, $baslik, $aciklama), 0, self::SMS_UST_SINIR, 'UTF-8');
    }

    /** Önce açıklamadaki "%N indirim", yoksa başlıktaki ilk "%N". */
    public static function indirim(string $aciklama, string $baslik): int
    {
        if (preg_match('/%%?(\d{1,2})\s*indirim/iu', $aciklama, $m) || preg_match('/%%?(\d{1,2})/u', $baslik, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    public static function kalanGun(string $bitis, DateTimeInterface $simdi): int
    {
        $son = new DateTimeImmutable($bitis, $simdi->getTimezone());

        return max(0, (int) $simdi->diff($son)->format('%r%a'));
    }
}
