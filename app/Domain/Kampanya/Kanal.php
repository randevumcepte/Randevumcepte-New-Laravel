<?php

namespace App\Domain\Kampanya;

/**
 * Kampanya (reklam) gönderim KANALI — kampanya_yonetimi.gorev_turu. TEK sözlük:
 * liste rozeti, sihirbaz kartları, rapor başlığı ve gönderim bayrakları buradan.
 *
 * Değerler eski web/mobil ile aynı (1 Arama · 2 SMS · 3 Bildirim · 4 Bilgilendirme).
 * Bilgilendirme kanalında indirim yoktur (eski sihirbaz indirim bölümünü gizliyordu).
 *
 * Gönderim eski monolitte kalır: Arama → `kampanyaarama:yap` cron'u (arama_ile_gonderim +
 * asistan_tarih_saat), SMS → `kampanyasms:gonder` (sms_ile_gonderim + sms_tarih_saat).
 */
enum Kanal: int
{
    case Arama = 1;
    case Sms = 2;
    case Bildirim = 3;
    case Bilgilendirme = 4;

    public static function degerden(mixed $v): ?self
    {
        return is_numeric($v) ? self::tryFrom((int) $v) : null;
    }

    /** Sihirbaz / rapor başlığı (eski kampanyadetay metni). */
    public function etiket(): string
    {
        return match ($this) {
            self::Arama         => 'Santral Arama',
            self::Sms           => 'SMS',
            self::Bildirim      => 'Uygulama Bildirimi',
            self::Bilgilendirme => 'Bilgilendirme',
        };
    }

    /** Liste rozeti (eski paket_kampanyalar CASE metni). */
    public function kisaEtiket(): string
    {
        return match ($this) {
            self::Arama         => 'Arama',
            self::Sms           => 'SMS',
            self::Bildirim      => 'Bildirim',
            self::Bilgilendirme => 'Bilgilendirme',
        };
    }

    public function aciklama(): string
    {
        return match ($this) {
            self::Arama         => 'Yapay zekâ asistanı müşterilerinizi arar, evet diyenlere indirim kodu gönderir.',
            self::Sms           => 'Seçtiğiniz kitleye planlanan saatte SMS gönderilir.',
            self::Bildirim      => 'Uygulamayı kullanan müşterilerinize bildirim gönderilir.',
            self::Bilgilendirme => 'İndirimsiz duyuru / bilgilendirme mesajı.',
        };
    }

    public function ikon(): string
    {
        return match ($this) {
            self::Arama         => 'bi-telephone-outbound',
            self::Sms           => 'bi-chat-square-text',
            self::Bildirim      => 'bi-bell',
            self::Bilgilendirme => 'bi-info-circle',
        };
    }

    public function indirimli(): bool
    {
        return $this !== self::Bilgilendirme;
    }

    /** Kanalın gönderim bayrağı kolonu (eski kayıt bu kolonu 1 yapıyordu). */
    public function bayrakKolonu(): string
    {
        return match ($this) {
            self::Arama         => 'arama_ile_gonderim',
            self::Sms           => 'sms_ile_gonderim',
            self::Bildirim, self::Bilgilendirme => 'bildirim_ile_gonderim',
        };
    }

    /** Listede "Saat" kolonunun okunduğu zaman kolonu (eski paket_kampanyalar ile aynı). */
    public function zamanKolonu(): string
    {
        return match ($this) {
            self::Arama         => 'asistan_tarih_saat',
            self::Sms           => 'sms_tarih_saat',
            self::Bildirim, self::Bilgilendirme => 'bildirim_tarih_saat',
        };
    }

    /** @return array{kod:int, ad:string, kisa:string, aciklama:string, ikon:string, indirimli:bool} */
    public function sozluk(): array
    {
        return [
            'kod' => $this->value, 'ad' => $this->etiket(), 'kisa' => $this->kisaEtiket(),
            'aciklama' => $this->aciklama(), 'ikon' => $this->ikon(), 'indirimli' => $this->indirimli(),
        ];
    }
}
