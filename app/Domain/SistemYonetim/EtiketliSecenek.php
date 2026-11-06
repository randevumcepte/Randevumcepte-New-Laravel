<?php

namespace App\Domain\SistemYonetim;

/** Etiket + rozet renkli enum'larin API bicimi: ekranlar sabit liste tutmaz, buradan okur. */
trait EtiketliSecenek
{
    abstract public function etiket(): string;

    abstract public function renk(): string;

    /** @return array{kod:int|string,etiket:string,renk:string} */
    public function dizi(): array
    {
        return ['kod' => $this->value, 'etiket' => $this->etiket(), 'renk' => $this->renk()];
    }

    /** @return list<array{kod:int|string,etiket:string,renk:string}> */
    public static function secenekler(): array
    {
        return array_map(fn (self $d) => $d->dizi(), self::cases());
    }

    /** Satir rozeti; tanimsiz eski deger de ham haliyle gorunur. */
    public static function rozet(int|string|null $kod): array
    {
        return ($kod === null ? null : self::tryFrom($kod))?->dizi() ?? ['kod' => $kod, 'etiket' => (string) ($kod ?? '—'), 'renk' => 'muted'];
    }
}
