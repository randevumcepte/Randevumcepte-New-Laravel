<?php

namespace App\Domain\Ozet;

/**
 * Ozet alt paneli LISTE SEKMELERI — TEK KAYNAK (yetki, baslik, ikon, bos mesaj, kolonlar).
 * Kolon tanimi API'den gelir; web ve Flutter tabloyu bu listeye gore cizer (UI'da sabit
 * kolon/baslik yok). Kolon tipi: metin | tarih | saat | telefon | para | durum.
 *
 * Eski dashboard $_sekmeTanim + dashboardSekme $_sekmeYetki ile birebir.
 */
enum OzetSekmesi: string
{
    case OnlineTalep = 'online_talep';
    case BugunkuRandevu = 'bugunku_randevu';
    case AcikAdisyon = 'acik_adisyon';
    case Alacak = 'alacak';
    case DogumGunu = 'dogum_gunu';

    /** Listelerin ust siniri (eski uclarla ayni). */
    public const LIMIT = 30;

    public function yetki(): string
    {
        return match ($this) {
            self::OnlineTalep, self::BugunkuRandevu => 'randevu.takvim_gor',
            self::AcikAdisyon                       => 'satis.tum_satis_gor',
            self::Alacak                            => 'finans.alacak_yonet',
            self::DogumGunu                         => 'musteri.liste_gor',
        };
    }

    public function ad(): string
    {
        return match ($this) {
            self::OnlineTalep    => 'Online Randevu Talepleri',
            self::BugunkuRandevu => 'Bugünkü Randevular',
            self::AcikAdisyon    => 'Açık Adisyonlar',
            self::Alacak         => 'Alacaklılar',
            self::DogumGunu      => 'Yaklaşan Doğum Günleri',
        };
    }

    public function ikon(): string
    {
        return match ($this) {
            self::OnlineTalep    => 'bi-globe2',
            self::BugunkuRandevu => 'bi-calendar-day',
            self::AcikAdisyon    => 'bi-receipt-cutoff',
            self::Alacak         => 'bi-cash-coin',
            self::DogumGunu      => 'bi-gift',
        };
    }

    public function bosMesaj(): string
    {
        return match ($this) {
            self::OnlineTalep    => 'Bekleyen randevu talebiniz bulunmamakta.',
            self::BugunkuRandevu => 'Bugün için randevu bulunmuyor.',
            self::AcikAdisyon    => 'Açık adisyon bulunmuyor.',
            self::Alacak         => 'Bekleyen alacak yok.',
            self::DogumGunu      => 'Yaklaşan doğum günü bulunmuyor.',
        };
    }

    /** @return array<int,array{alan:string,baslik:string,tip:string}> */
    public function kolonlar(): array
    {
        $k = fn (string $alan, string $baslik, string $tip = 'metin') => ['alan' => $alan, 'baslik' => $baslik, 'tip' => $tip];
        $musteri = [$k('musteri', 'Müşteri'), $k('telefon', 'Telefon', 'telefon')];

        return match ($this) {
            self::OnlineTalep    => [$k('tarih', 'Tarih', 'tarih'), $k('saat', 'Saat', 'saat'), ...$musteri, $k('hizmet', 'Hizmet'), $k('durum', 'Durum', 'durum')],
            self::BugunkuRandevu => [$k('saat', 'Saat', 'saat'), ...$musteri, $k('hizmet', 'Hizmet'), $k('personel', 'Personel / Kaynak'), $k('durum', 'Durum', 'durum')],
            self::AcikAdisyon    => [$k('tarih', 'Satış Tarihi', 'tarih'), ...$musteri, $k('toplam', 'Toplam', 'para'), $k('kalan', 'Kalan', 'para')],
            self::Alacak         => [$k('planlanan_odeme_tarihi', 'Planlanan Ödeme', 'tarih'), ...$musteri, $k('tutar', 'Tutar', 'para')],
            self::DogumGunu      => [$k('dogum_tarihi', 'Doğum Tarihi', 'tarih'), ...$musteri, $k('kalan_gun', 'Kalan Gün')],
        };
    }
}
