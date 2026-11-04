<?php

namespace App\Domain\Bildirim;

/** bildirim_kimlikleri.platform sözlüğü — eski NotificationService web token'ını buna göre doğru Firebase projesinden yollar. */
enum CihazPlatformu: string
{
    case ANDROID = 'android';
    case IOS = 'ios';
    case WEB = 'web';

    /** @return list<string> */
    public static function kodlar(): array
    {
        return array_column(self::cases(), 'value');
    }
}
