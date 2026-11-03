<?php

namespace App\Domain\Asistan;

/**
 * E-Asistan gorev turleri — her turun tablosu, iptal bayragi ve "gorev gunu"
 * ile kaydin tarihi arasindaki fark TEK yerde.
 *
 * Gorev gunu D icin (eski web easistandata ile birebir):
 *   randevu  : randevu tarihi D+1 (1 gun once aranir)
 *   alacak   : planlanan odeme D+2 (2 gun once aranir)
 *   kampanya : asistan_tarih_saat D (tanitim o gun yapilir)
 *   musteri  : asistanin D gunu ekledigi musteriler (yalniz bilgi; eski mobil uc)
 *
 * Not: eski mobil uc kampanyayi D+1'de ariyordu; bu sayfanin kanonigi web oldugu
 * icin D kullanilir (tanitim aramasi asistan_tarih_saat aninda yapilir).
 */
enum GorevTuru: string
{
    case Randevu = 'randevu';
    case Alacak = 'alacak';
    case Kampanya = 'kampanya';
    case Musteri = 'musteri';

    public function tablo(): string
    {
        return match ($this) {
            self::Randevu  => 'randevular',
            self::Alacak   => 'alacaklar',
            self::Kampanya => 'kampanya_yonetimi',
            self::Musteri  => 'musteri_portfoy',
        };
    }

    /** Gorevi iptal eden bayrak kolonu; iptal edilemeyen turde null. */
    public function iptalKolonu(): ?string
    {
        return match ($this) {
            self::Randevu, self::Alacak => 'hatirlatma_gorevi_iptal',
            self::Kampanya              => 'tanitim_gorev_iptal',
            self::Musteri               => null,
        };
    }

    public function gunFarki(): int
    {
        return match ($this) {
            self::Randevu => 1,
            self::Alacak  => 2,
            self::Kampanya, self::Musteri => 0,
        };
    }

    /** Gorev gunu (Y-m-d) → bu turde aranacak kaydin tarihi (Y-m-d). */
    public function hedefTarih(string $gorevGunu): string
    {
        return date('Y-m-d', strtotime($gorevGunu.' +'.$this->gunFarki().' days'));
    }

    public function baslik(): string
    {
        return match ($this) {
            self::Randevu  => 'Randevu Hatırlatması',
            self::Alacak   => 'Alacak Hatırlatması',
            self::Kampanya => 'Kampanya Tanıtımı',
            self::Musteri  => 'Yeni Müşteri',
        };
    }

    public function iptalMesaji(): string
    {
        return match ($this) {
            self::Randevu  => 'Randevu hatırlatma görevi başarıyla iptal edildi',
            self::Alacak   => 'Alacak hatırlatma görevi başarıyla iptal edildi',
            self::Kampanya => 'Kampanya tanıtım görevi başarıyla iptal edildi',
            self::Musteri  => '',
        };
    }

    /** @return array<int,string> Iptal edilebilen turlerin degerleri (route kisiti icin). */
    public static function iptalEdilebilenler(): array
    {
        return array_values(array_map(
            fn (self $t) => $t->value,
            array_filter(self::cases(), fn (self $t) => $t->iptalKolonu() !== null)
        ));
    }
}
