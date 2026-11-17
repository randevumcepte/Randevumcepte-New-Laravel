<?php

namespace App\Domain\Anket;

/**
 * Memnuniyet anketi davetinin ilk kanalı (salonlar.anket_gonderim_kanali) — SAF, TEK KAYNAK.
 *   whatsapp (varsayılan) : WhatsApp bağlıysa önce WA, olmazsa SMS
 *   sms                   : önce SMS; SMS istisna verirse ve WA bağlıysa WA yedek
 * Eski monolitte StoreAdminController::anketSmsGonder aynı kuralı uygular.
 */
final class AnketGonderimKanali
{
    public const KOLON = 'anket_gonderim_kanali';
    public const WHATSAPP = 'whatsapp';
    public const SMS = 'sms';
    public const DEGERLER = [self::WHATSAPP, self::SMS];

    public static function normalize(mixed $v): string
    {
        return in_array($v, self::DEGERLER, true) ? $v : self::WHATSAPP;
    }

    public static function smsOnceMi(?object $salon): bool
    {
        return self::normalize($salon->{self::KOLON} ?? null) === self::SMS;
    }
}
