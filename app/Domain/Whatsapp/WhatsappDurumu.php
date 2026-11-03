<?php

namespace App\Domain\Whatsapp;

/**
 * Salonun WhatsApp baglanti + kontor durumu (saf deger nesnesi; DB/saat bilmez).
 * Eski layout header'indaki WhatsApp rozeti + App\Services\KontorServisi::kontorlusDonemMi
 * kurallarinin TEK yeri (web header + ileride Flutter ayni cevabi kullanir).
 *
 *   bagli          : whatsapp_aktif=1 VE whatsapp_durum='connected'
 *   kontorlu donem : salon deneme bitisi gectiyse; bitis yok/bozuksa global baslangic
 *   kontor rozeti  : bagliyken bakiye varsa YA DA kontorlu donemdeyse
 *   kritik         : kontorlu donemde bakiye bitti (mesajlar SMS'e duser)
 */
final class WhatsappDurumu
{
    /** Eski KontorServisi::BASLANGIC ile ayni — salon-ozel deneme bitisi yoksa kullanilir. */
    public const KONTOR_BASLANGIC = '2026-09-01';

    public function __construct(
        public readonly bool $bagli,
        public readonly string $numara,
        public readonly int $kontor,
        public readonly bool $kontorluDonem,
    ) {}

    /** @param object $salon salonlar satiri (whatsapp_* kolonlari) */
    public static function salondan(object $salon, string $bugun): self
    {
        $aktif = (int) ($salon->whatsapp_aktif ?? 0) === 1;

        return new self(
            bagli: $aktif && (string) ($salon->whatsapp_durum ?? '') === 'connected',
            numara: trim((string) ($salon->whatsapp_numara ?? '')),
            kontor: (int) ($salon->whatsapp_kontor ?? 0),
            kontorluDonem: self::kontorluDonemMi($salon->whatsapp_deneme_bitis ?? null, $bugun),
        );
    }

    /** $bugun ve $denemeBitis 'Y-m-d' (bitis gunu dahil ucretsiz). */
    public static function kontorluDonemMi(?string $denemeBitis, string $bugun): bool
    {
        $bitis = substr((string) $denemeBitis, 0, 10);
        if ($bitis !== '' && $bitis !== '0000-00-00') {
            return $bugun > $bitis;
        }

        return $bugun >= self::KONTOR_BASLANGIC;
    }

    public function kontorGoster(): bool
    {
        return $this->bagli && ($this->kontor > 0 || $this->kontorluDonem);
    }

    public function kontorKritik(): bool
    {
        return $this->kontorluDonem && $this->kontor <= 0;
    }

    public function dizi(): array
    {
        return [
            'bagli'         => $this->bagli,
            'numara'        => $this->bagli && $this->numara !== '' ? $this->numara : null,
            'kontor'        => $this->kontorGoster() ? $this->kontor : null,
            'kontor_kritik' => $this->kontorGoster() && $this->kontorKritik(),
        ];
    }
}
