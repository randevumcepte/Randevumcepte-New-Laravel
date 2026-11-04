<?php

namespace App\Domain\Whatsapp;

/** Sistem sahibine giden "kontör talebi" bildirim metni (saf) — eski whatsappPaketTalep ile aynı içerik. */
final class KontorTalepMesaji
{
    private const NOT_UZUNLUK = 120;

    /** @param object $salon salonlar satırı (salon_adi, yetkili_adi?, yetkili_telefon?, telefon_1?, id) */
    public static function olustur(object $salon, array $paket, ?string $iletisim, string $zaman): string
    {
        $tel = ($salon->yetkili_telefon ?? '') ?: ($salon->telefon_1 ?? '');
        $iletisim = trim((string) $iletisim);

        return implode("\n", array_filter([
            '💰 KONTÖR TALEBİ',
            'Salon: '.(($salon->salon_adi ?? '') ?: '-'),
            'Paket: '.$paket['ad'].' ('.$paket['fiyat_metin'].')',
            ! empty($salon->yetkili_adi) ? 'Yetkili: '.$salon->yetkili_adi : null,
            $tel ? 'Tel: '.$tel : null,
            $iletisim !== '' ? 'İletişim/Not: '.mb_substr($iletisim, 0, self::NOT_UZUNLUK) : null,
            'ID: '.$salon->id,
            $zaman,
        ]));
    }
}
