<?php

namespace App\Services\Whatsapp\Kopru;

/** Köprü HTTP cevabı. kod=0: köprüye hiç ulaşılamadı (zaman aşımı / bağlantı reddi). */
final class KopruCevabi
{
    public function __construct(
        public readonly int $kod,
        public readonly array $govde = [],
    ) {}

    public function basarili(): bool
    {
        return $this->kod >= 200 && $this->kod < 300;
    }

    public function ulasildi(): bool
    {
        return $this->kod !== 0;
    }

    public function hata(): string
    {
        return (string) ($this->govde['error'] ?? '');
    }
}
