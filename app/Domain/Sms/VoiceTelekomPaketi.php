<?php

namespace App\Domain\Sms;

/**
 * VoiceTelekom `sms/create` gövdesi — eski App\VoiceTelekom\Sms\SendMultiSms::toString +
 * SMSController::cokluSMSGonderVoiceTelekom ile aynı alanlar (saf; test edilebilir).
 */
final class VoiceTelekomPaketi
{
    /** @param string[] $numaralar */
    public static function govde(string $baslik, string $mesaj, array $numaralar, string $gonderen, string $ozelId): array
    {
        return [
            'type'             => 1,
            'sendingType'      => 1,
            'title'            => $baslik,
            'encoding'         => 0,
            'content'          => $mesaj,
            'numbers'          => array_values($numaralar),
            'sender'           => $gonderen,
            'periodicSettings' => null,
            'sendingDate'      => null,
            'validity'         => 60,
            'commercial'       => false,
            'skipAhsQuery'     => true,
            'customID'         => $ozelId,
        ];
    }
}
