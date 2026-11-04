<?php

namespace App\Services\Oturum\Hesap;

/** Hesap turleri, deneme sirasiyla: telefon iki tabloda da varsa isletme hesabi kazanir (v1 girisiyle ayni). */
class HesapListesi
{
    public function __construct(private YetkiliHesabi $yetkili, private MusteriHesabi $musteri) {}

    /** @return OturumHesabi[] hesap_turu verilmediyse hepsi */
    public function secili(?string $tur): array
    {
        $hepsi = [$this->yetkili, $this->musteri];

        return $tur === null ? $hepsi : array_values(array_filter($hepsi, fn (OturumHesabi $h) => $h->tur() === $tur));
    }
}
