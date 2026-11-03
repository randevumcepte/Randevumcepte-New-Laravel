<?php

namespace App\Domain\Asistan;

/**
 * E-Asistan ayarlari — 'salon_esistan_ayarlari' satirlarinin (salon_id, ayar_id,
 * acik_kapali) TEK tanim kaynagi. Deger = ayar_id; siralama = ekrandaki sira
 * (eski "Asistanım > Ayarlar" sekmesiyle birebir). Web ve Flutter listeyi
 * GET /api/v2/asistan/ayarlar'dan alir (UI'da sabit liste yok).
 *
 * ayar_id 5 (eski stok hatirlatma) ekranda yoktur; v2 ona DOKUNMAZ (eski kayit
 * her kaydette 5'i kapatiyordu). ayar_id 7 eski kaydetmede hic yazilmiyordu
 * (toggle etkisizdi); v2 yazar.
 */
enum AsistanAyari: int
{
    case Alacak = 1;
    case Randevu = 4;
    case DogumGunu = 7;
    case OnGorusme = 2;
    case Kampanya = 8;
    case TekrarArama = 3;
    case KaraListe = 6;

    /** Saat secimi (salonlar.e_asistan_hatirlatma) — "N saat sonra tekrar dene". */
    public const TEKRAR_SAAT_MIN = 1;
    public const TEKRAR_SAAT_MAX = 23;

    public function anahtar(): string
    {
        return match ($this) {
            self::Alacak      => 'alacak',
            self::Randevu     => 'randevu',
            self::DogumGunu   => 'dogum_gunu',
            self::OnGorusme   => 'on_gorusme',
            self::Kampanya    => 'kampanya',
            self::TekrarArama => 'tekrar_arama',
            self::KaraListe   => 'kara_liste',
        };
    }

    public function baslik(): string
    {
        return match ($this) {
            self::Alacak      => 'Alacak Hatırlatma',
            self::Randevu     => 'Randevu Hatırlatma',
            self::DogumGunu   => 'Doğum Günü Kutlama',
            self::OnGorusme   => 'Ön Görüşme Hatırlatma',
            self::Kampanya    => 'Kampanya Hatırlatma',
            self::TekrarArama => 'Tekrar Arama',
            self::KaraListe   => 'Kara Liste Kontrolü',
        };
    }

    public function aciklama(): string
    {
        return match ($this) {
            self::Alacak      => 'Alacak hatırlatmalarını planlanan ödeme tarihinden 2 gün önce ara.',
            self::Randevu     => 'Randevu hatırlatmalarını randevudan 1 gün önce ara.',
            self::DogumGunu   => 'Doğum günü olan müşterilere kutlama araması yapılsın. (Özel gönderici adı gerekir.)',
            self::OnGorusme   => 'Ön görüşme hatırlatmalarını görüşmeden 1 gün önce ara.',
            self::Kampanya    => 'Kampanya ve promosyon bilgilendirme aramaları yapılsın.',
            self::TekrarArama => 'Ulaşılamayan aramalar belirlenen süre sonra tekrar denensin.',
            self::KaraListe   => 'Kara listedeki numaralara hatırlatma araması yapılmasın.',
        };
    }

    /** bootstrap-icons sinifi (web); Flutter kendi ikonunu anahtardan esler. */
    public function ikon(): string
    {
        return match ($this) {
            self::Alacak      => 'bi-cash-coin',
            self::Randevu     => 'bi-calendar-check',
            self::DogumGunu   => 'bi-cake2',
            self::OnGorusme   => 'bi-chat-left-text',
            self::Kampanya    => 'bi-megaphone',
            self::TekrarArama => 'bi-arrow-repeat',
            self::KaraListe   => 'bi-slash-circle',
        };
    }

    /** Bu ayar "tekrar deneme saati" secimini tasir mi? */
    public function saatSecimli(): bool
    {
        return $this === self::TekrarArama;
    }

    public static function anahtardan(string $anahtar): ?self
    {
        foreach (self::cases() as $a) {
            if ($a->anahtar() === $anahtar) {
                return $a;
            }
        }

        return null;
    }
}
