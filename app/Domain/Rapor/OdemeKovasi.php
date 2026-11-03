<?php

namespace App\Domain\Rapor;

/**
 * Odeme yontemi kovalari — kasa / gun sonu / isletme ozeti AYNI siniflandirmayi kullanir.
 *
 * Eski kodda iki ayri kural vardi: kasa + gun sonu odeme_yontemi_id (1..4), isletme ozeti
 * ve dashboard ise yontem ADINDA alt-dize arama (nakit/kart/havale). Ad aramasi kirilgandi
 * (ornegin "Online Ödeme" Diger'e dusuyordu); v2 id esasini kullanir. 5 = Senet ve
 * tanimsiz yontemler Diger'dir (eski gun sonu ile ayni).
 */
enum OdemeKovasi: string
{
    case Nakit = 'nakit';
    case Kart = 'kredikarti';
    case Havale = 'havale';
    case Online = 'online';
    case Diger = 'diger';

    public static function icin(?int $odemeYontemiId): self
    {
        return match ((int) $odemeYontemiId) {
            1       => self::Nakit,
            2       => self::Kart,
            3       => self::Havale,
            4       => self::Online,
            default => self::Diger,
        };
    }

    public function etiket(): string
    {
        return match ($this) {
            self::Nakit  => 'Nakit',
            self::Kart   => 'Kredi Kartı',
            self::Havale => 'Havale / EFT',
            self::Online => 'Online Ödeme',
            self::Diger  => 'Diğer',
        };
    }

    /** Her kovaya 0 — toplama baslangici. @return array<string,float> */
    public static function sifir(): array
    {
        return array_fill_keys(array_map(fn (self $k) => $k->value, self::cases()), 0.0);
    }

    /**
     * [{odeme_yontemi_id, toplam}] satirlarini kovalara toplar (kurus yuvarlamali).
     *
     * @param  iterable<object|array>  $satirlar
     * @return array<string,float>
     */
    public static function topla(iterable $satirlar): array
    {
        $kova = self::sifir();
        foreach ($satirlar as $s) {
            $s = (array) $s;
            $k = self::icin(isset($s['odeme_yontemi_id']) ? (int) $s['odeme_yontemi_id'] : null)->value;
            $kova[$k] = round($kova[$k] + (float) ($s['toplam'] ?? 0), 2);
        }

        return $kova;
    }

    /** UI etiket listesi: [{kod, ad}] (sabit liste UI'da tutulmaz). */
    public static function liste(): array
    {
        return array_map(fn (self $k) => ['kod' => $k->value, 'ad' => $k->etiket()], self::cases());
    }
}
