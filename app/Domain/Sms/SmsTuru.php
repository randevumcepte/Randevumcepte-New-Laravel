<?php

namespace App\Domain\Sms;

use App\Exceptions\GecersizIslemException;

/**
 * SMS gönderim TÜRÜ — sms_iletim_raporlari.tur sayıları ile VoiceTelekom paket başlığı (title) TEK
 * yerde (eski SMSController::VT_TUR_BASLIKLARI + vtBaslikTanimla + rapor sayfası $turMap).
 * kod = eski rapor anahtarları (bildirim/grup/filtre/toplu/kampanya/etkinlik) — Flutter aynı.
 */
enum SmsTuru: int
{
    case Bildirim = 1;
    case Grup = 2;
    case Filtre = 3;
    case Toplu = 4;
    case Kampanya = 5;
    case Etkinlik = 6;

    public function kod(): string
    {
        return match ($this) {
            self::Bildirim => 'bildirim',
            self::Grup     => 'grup',
            self::Filtre   => 'filtre',
            self::Toplu    => 'toplu',
            self::Kampanya => 'kampanya',
            self::Etkinlik => 'etkinlik',
        };
    }

    /** Rapor sekmesi etiketi. */
    public function ad(): string
    {
        return match ($this) {
            self::Bildirim => 'Bildirim SMS',
            self::Grup     => 'Grup SMS',
            self::Filtre   => 'Filtreli SMS',
            self::Toplu    => 'Toplu SMS',
            self::Kampanya => 'Kampanya SMS',
            self::Etkinlik => 'Etkinlik SMS',
        };
    }

    /** VoiceTelekom'a yazılan paket başlığı (raporda türü bu başlık belirler). */
    public function saglayiciBasligi(): string
    {
        return match ($this) {
            self::Bildirim => 'Bildirim',
            self::Grup     => 'Grup SMS',
            self::Filtre   => 'Filtreli SMS',
            self::Toplu    => 'Toplu SMS',
            self::Kampanya => 'Kampanya',
            self::Etkinlik => 'Etkinlik',
        };
    }

    public static function koddan(?string $kod): self
    {
        foreach (self::cases() as $t) {
            if ($t->kod() === $kod) {
                return $t;
            }
        }
        throw new GecersizIslemException('Geçersiz SMS türü');
    }

    /** Paket başlığı → tür (büyük/küçük harf duyarsız); tanınmayan başlık eskisi gibi Bildirim sayılır. */
    public static function basliktan(?string $baslik): self
    {
        $aranan = mb_strtolower(trim((string) $baslik));
        foreach (self::cases() as $t) {
            if (mb_strtolower($t->saglayiciBasligi()) === $aranan) {
                return $t;
            }
        }

        return self::Bildirim;
    }

    /** Elle gönderilebilen türler (sekmeler: Toplu SMS, Filtreli SMS). */
    public static function gonderilebilir(?string $kod): self
    {
        $t = self::koddan($kod);
        if (! in_array($t, [self::Toplu, self::Filtre], true)) {
            throw new GecersizIslemException('Bu SMS türü buradan gönderilemez');
        }

        return $t;
    }

    /** Rapor sekmeleri (eski web + Flutter ile aynı 5 tür; etkinlik raporu ekranda hiç gösterilmedi). */
    public static function raporSekmeleri(): array
    {
        return array_map(
            fn (self $t) => ['kod' => $t->kod(), 'ad' => $t->ad()],
            [self::Bildirim, self::Grup, self::Filtre, self::Toplu, self::Kampanya],
        );
    }
}
