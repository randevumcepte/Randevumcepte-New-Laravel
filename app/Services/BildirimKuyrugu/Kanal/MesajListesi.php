<?php

namespace App\Services\BildirimKuyrugu\Kanal;

/** Payload'daki mesaj listesi (sms, form_link): alicisi ya da metni bos olanlar elenir, anahtarlar yeniden numaralanir. */
final class MesajListesi
{
    public static function gecerliler(array $payload): array
    {
        return array_values(array_filter((array) ($payload['mesajlar'] ?? []), fn ($m) => ! empty($m['to']) && ! empty($m['message'])));
    }
}
