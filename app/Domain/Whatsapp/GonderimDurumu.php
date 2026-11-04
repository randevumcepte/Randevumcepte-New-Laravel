<?php

namespace App\Domain\Whatsapp;

/**
 * whatsapp_gonderim_loglari.durum — web + Flutter aynı etiket / renk / açıklamayı sözlükten çizer.
 */
enum GonderimDurumu: int
{
    case Kuyrukta = 0;
    case Gonderildi = 1;
    case Basarisiz = 2;
    case SmsDustu = 3;

    public function ad(): string
    {
        return match ($this) {
            self::Kuyrukta   => 'Kuyrukta',
            self::Gonderildi => 'Gönderildi',
            self::Basarisiz  => 'Başarısız',
            self::SmsDustu   => "SMS'e Düştü",
        };
    }

    public function renk(): string
    {
        return match ($this) {
            self::Kuyrukta   => 'mavi',
            self::Gonderildi => 'yesil',
            self::Basarisiz  => 'kirmizi',
            self::SmsDustu   => 'turuncu',
        };
    }

    public function aciklama(): string
    {
        return match ($this) {
            self::Kuyrukta   => 'Mesaj gönderim sırasında bekliyor, kısa süre içinde işlenecek.',
            self::Gonderildi => "Mesaj müşterinin WhatsApp'ına başarıyla iletildi.",
            self::Basarisiz  => 'Mesaj hiçbir kanaldan gönderilemedi.',
            self::SmsDustu   => "WhatsApp'a ulaşılamadı, sistem otomatik olarak SMS gönderdi. Bu SMS bakiyenizden düşer.",
        };
    }

    /** @return list<array{kod:int, ad:string, renk:string, aciklama:string}> */
    public static function sozluk(): array
    {
        return array_map(fn (self $d) => [
            'kod' => $d->value, 'ad' => $d->ad(), 'renk' => $d->renk(), 'aciklama' => $d->aciklama(),
        ], self::cases());
    }
}
