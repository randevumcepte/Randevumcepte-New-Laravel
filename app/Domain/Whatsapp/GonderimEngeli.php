<?php

namespace App\Domain\Whatsapp;

/**
 * WhatsApp gönderimini kuyruğa yazmadan önce görülebilen engeller (saf kural; DB/saat bilmez). Gönderimin
 * asıl kapısı eski WhatsAppService::sendReminder'dır; sıra ve kodlar onunla aynıdır. Cloud API'de günlük
 * tavan yoktur.
 */
final class GonderimEngeli
{
    public const GUNLUK_TAVAN = 'daily-cap-reached';

    public const MESAI_DISI = 'outside-business-hours';

    public const KONTOR_YETERSIZ = 'kontor-yetersiz';

    private const CLOUD_API = 'cloud_api';

    private const VARSAYILAN_METIN = 'Mesaj gonderilemedi. Lutfen tekrar deneyin.';

    public static function coz(string $saglayici, int $gunlukLimit, int $bugunGonderilen, bool $mesaiIci, bool $kontorluDonem, int $kontor): ?string
    {
        if ($saglayici !== self::CLOUD_API && $bugunGonderilen >= $gunlukLimit) {
            return self::GUNLUK_TAVAN;
        }
        if (! $mesaiIci) {
            return self::MESAI_DISI;
        }

        return $kontorluDonem && $kontor < 1 ? self::KONTOR_YETERSIZ : null;
    }

    /** Müşteri kartındaki gönderim sonucunda gösterilen metin. */
    public static function metin(?string $kod): string
    {
        return match ($kod) {
            self::GUNLUK_TAVAN => 'Gunluk WhatsApp mesaj limitiniz doldu.',
            self::MESAI_DISI   => 'Su an calisma saatleri disinda, mesaj gonderilemedi.',
            default            => self::VARSAYILAN_METIN,
        };
    }
}
