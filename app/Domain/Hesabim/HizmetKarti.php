<?php

namespace App\Domain\Hesabim;

/**
 * "Aldığım Hizmetler" kartı (saf değer nesnesi). JSON alanları eski /api/v1/hesabim ile aynı —
 * Flutter HesabimHizmet.fromJson değişmeden okur. `icon` Flutter'ın eşlediği fa-* adı,
 * `ikon` web'in bootstrap-icons sınıfıdır.
 */
final class HizmetKarti
{
    public function __construct(
        public readonly string $kod,
        public readonly string $ad,
        public readonly string $aciklama,
        public readonly string $icon,
        public readonly string $ikon,
        public readonly string $renk,
        public readonly bool $aktif,
        public readonly string $periyot,
        public readonly ?string $baslangic = null,
        public readonly ?string $bitis = null,
        public readonly ?int $kalanGun = null,
        public readonly bool $deneme = false,
        public readonly ?string $denemeEtiket = null,
    ) {}

    /** Pakete dahil, süresiz ek hizmet. */
    public static function pakete(string $kod, string $ad, string $aciklama, string $icon, string $ikon, string $renk, bool $aktif = true): self
    {
        return new self($kod, $ad, $aciklama, $icon, $ikon, $renk, $aktif, 'Pakete dahil');
    }

    public function dizi(): array
    {
        return [
            'kod'          => $this->kod,
            'ad'           => $this->ad,
            'aciklama'     => $this->aciklama,
            'icon'         => $this->icon,
            'ikon'         => $this->ikon,
            'renk'         => $this->renk,
            'aktif'        => $this->aktif,
            'periyot'      => $this->periyot,
            'baslangic'    => $this->baslangic,
            'bitis'        => $this->bitis,
            'kalan_gun'    => $this->kalanGun,
            'deneme'       => $this->deneme,
            'deneme_label' => $this->denemeEtiket,
        ];
    }
}
