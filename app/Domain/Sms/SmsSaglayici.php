<?php

namespace App\Domain\Sms;

/**
 * Salonun SMS sağlayıcısı — seçim kuralı TEK yerde (bakiye + rapor + gönderim aynı kuralı kullanır):
 * salonlar.yeni_sms=1 → VoiceTelekom (sms_user_name / sms_secret), aksi halde Efetech (sms_apikey).
 */
enum SmsSaglayici
{
    case VoiceTelekom;
    case Efetech;

    public static function salondan(object $salon): self
    {
        return (int) ($salon->yeni_sms ?? 0) === 1 ? self::VoiceTelekom : self::Efetech;
    }

    /** Gönderim için gerekli kimlik + gönderici başlığı tanımlı mı? */
    public function hesapTanimli(object $salon): bool
    {
        $baslik = trim((string) ($salon->sms_baslik ?? '')) !== '';

        return $baslik && match ($this) {
            self::VoiceTelekom => trim((string) ($salon->sms_user_name ?? '')) !== '' && trim((string) ($salon->sms_secret ?? '')) !== '',
            self::Efetech      => trim((string) ($salon->sms_apikey ?? '')) !== '',
        };
    }
}
