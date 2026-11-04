<?php

namespace App\Domain\Whatsapp;

/**
 * WhatsApp oturumunun bağlantı durumu sözlüğü + köprü cevabının kanonik koda çevrilmesi (saf).
 * Kodlar salonlar.whatsapp_durum'a da yazılır; eski monolit aynı değerleri okuduğu için DEĞİŞTİRİLMEZ.
 * Normalize kuralı eski WhatsAppRouterService::normalizeWhatsmeowStatus ile aynı.
 */
final class BaglantiDurumu
{
    public const BAGLI = 'connected';

    public const KOPUK = 'disconnected';

    public const SERVIS_KAPALI = 'servis-kapali';

    public const CIKIS_YAPILDI = 'cikis-yapildi';

    public const BAGLANIYOR = 'connecting';

    public const QR_BEKLENIYOR = 'qr-pending';

    private const TANIMLAR = [
        self::BAGLI                => ['ad' => 'Bağlı', 'renk' => 'yesil'],
        self::QR_BEKLENIYOR        => ['ad' => 'QR Bekleniyor', 'renk' => 'turuncu'],
        self::BAGLANIYOR           => ['ad' => 'Bağlanıyor…', 'renk' => 'mavi'],
        self::KOPUK                => ['ad' => 'Bağlantı Kesildi', 'renk' => 'gri'],
        'banned-or-loggedout'      => ['ad' => 'Oturum Sonlandı (Ban Riski)', 'renk' => 'kirmizi'],
        'auto-paused-ban-risk'     => ['ad' => 'Ban Riski — Otomatik Durduruldu', 'renk' => 'kirmizi'],
        'rate-limited'             => ['ad' => 'Rate-Limit (Durduruldu)', 'renk' => 'kirmizi'],
        'not-initialized'          => ['ad' => 'Bağlı Değil', 'renk' => 'gri'],
        self::SERVIS_KAPALI        => ['ad' => 'Servis Kapalı', 'renk' => 'kirmizi'],
        self::CIKIS_YAPILDI        => ['ad' => 'Çıkış Yapıldı', 'renk' => 'gri'],
    ];

    /** Köprü gövdesi → kanonik kod: {connected:true} = bağlı; durum yok / 'no-session' = kopuk. */
    public static function koprudan(?array $govde): string
    {
        if (! empty($govde['connected'])) {
            return self::BAGLI;
        }
        $durum = (string) ($govde['status'] ?? '');

        return $durum === '' || $durum === 'no-session' ? self::KOPUK : $durum;
    }

    /** @return array{ad:string, renk:string} Bilinmeyen kod olduğu gibi gösterilir (köprü yeni durum ekleyebilir). */
    public static function tanim(string $kod): array
    {
        return self::TANIMLAR[$kod] ?? ['ad' => $kod, 'renk' => 'gri'];
    }

    /** @return list<array{kod:string, ad:string, renk:string}> */
    public static function sozluk(): array
    {
        return array_map(fn ($kod, $t) => ['kod' => (string) $kod] + $t, array_keys(self::TANIMLAR), self::TANIMLAR);
    }
}
