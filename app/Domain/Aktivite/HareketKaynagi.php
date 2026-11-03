<?php

namespace App\Domain\Aktivite;

/**
 * Hareketin KAYNAĞI (hangi kanaldan yapıldı) — salon_aktivite_log.meta.kaynak'tan türetilir.
 *
 *   'mobil'               → eski Audit::logApi (mobil v1 API)
 *   'panel' | 'panel_oto' → eski web paneli (elle Audit::log / PanelAktiviteLog middleware)
 *   'v2'                  → App\Support\AktiviteLog (yeni /api/v2 — web v2 paneli + Flutter v2)
 *   kaynak yok            → eski elle Audit::log çağrıları: kullanıcı varsa panel, yoksa sistem
 */
enum HareketKaynagi: string
{
    case PANEL = 'panel';
    case MOBIL = 'mobil';
    case V2 = 'v2';
    case SISTEM = 'sistem';

    /** meta.kaynak değerleri → kaynak (eşleşmeyen değer kaynak yok sayılır). */
    private const META = ['panel' => self::PANEL, 'panel_oto' => self::PANEL, 'mobil' => self::MOBIL, 'v2' => self::V2];

    /** Satırdan kaynak: önce meta.kaynak, yoksa kullanıcı tipine göre panel / sistem. */
    public static function cozumle(?array $meta, ?string $kullaniciTipi): self
    {
        return self::META[$meta['kaynak'] ?? ''] ?? ($kullaniciTipi === 'sistem' ? self::SISTEM : self::PANEL);
    }

    /** İstek / filtre kodundan; geçersiz veya boşsa null. */
    public static function koddan(?string $kod): ?self
    {
        return $kod === null || $kod === '' ? null : self::tryFrom($kod);
    }

    /** Bu kaynağı işaret eden meta.kaynak değerleri (sorgu filtresi için). */
    public function metaDegerleri(): array
    {
        return array_keys(array_filter(self::META, fn (self $k) => $k === $this));
    }

    /** meta.kaynak hiç YOKSA bu kaynağa düşen kullanıcı tipi koşulu: true=sistem, false=sistem-dışı, null=düşmez. */
    public function kaynaksizSistemMi(): ?bool
    {
        return match ($this) {
            self::PANEL  => false,
            self::SISTEM => true,
            default      => null,
        };
    }

    public function etiket(): string
    {
        return match ($this) {
            self::PANEL  => 'Panel',
            self::MOBIL  => 'Mobil',
            self::V2     => 'v2',
            self::SISTEM => 'Sistem',
        };
    }

    /** bootstrap-icons sınıfı (web). */
    public function ikon(): string
    {
        return match ($this) {
            self::PANEL  => 'bi-display',
            self::MOBIL  => 'bi-phone',
            self::V2     => 'bi-stars',
            self::SISTEM => 'bi-cpu',
        };
    }

    /** @return array{kod:string, etiket:string, ikon:string} */
    public function dizi(): array
    {
        return ['kod' => $this->value, 'etiket' => $this->etiket(), 'ikon' => $this->ikon()];
    }
}
