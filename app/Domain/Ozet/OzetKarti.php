<?php

namespace App\Domain\Ozet;

/**
 * Ozet (dashboard) KARTLARI — TEK KAYNAK. Her kartin gorunurluk yetkisi (OR), studyo
 * gizlemesi ve donem secenekleri burada; hem acilis verisi (hangi kart cizilir) hem de
 * kartin veri ucunun kapisi (GuvenlikKapisi) bu tanimi kullanir → UI ile API ayrismaz.
 *
 * Eski isletmeadmin/dashboard.blade.php @yetki bloklari ve StoreAdminController::dashboard*
 * uclarindaki dashYetkiYoksa403 anahtarlariyla BIREBIR.
 */
enum OzetKarti: string
{
    case Kasa = 'kasa';
    case Anket = 'anket';
    case Randevu = 'randevu';
    case Cark = 'cark';
    case Akis = 'akis';
    case Personel = 'personel';
    case Karsilastirma = 'karsilastirma';

    /** @return array<string> gorunurluk yetkileri (OR) */
    public function yetkiler(): array
    {
        return match ($this) {
            self::Kasa     => ['rapor.kasa', 'personel.kendi_ciro_gor'],
            self::Anket    => ['pazarlama.anket_yonet'],
            self::Randevu  => ['randevu.takvim_gor'],
            self::Cark     => ['pazarlama.cark_yonet'],
            self::Akis     => ['rapor.kasa'],                       // tahsilat tutarlari icerir
            self::Personel => ['personel.liste_gor', 'rapor.personel_performans'],
            // Mobil performans blogu: yonetici rapor.satis, personel kendi cirosu.
            self::Karsilastirma => ['rapor.satis', 'personel.kendi_ciro_gor'],
        };
    }

    public function baslik(): string
    {
        return match ($this) {
            self::Kasa     => 'Kasa İstatistikleri',
            self::Anket    => 'Memnuniyet Anketi',
            self::Randevu  => 'Randevu Ayrıntıları',
            self::Cark     => 'Çarkıfelek',
            self::Akis     => 'Bugünkü Akış',
            self::Personel => 'Personel Anlık Durum',
            self::Karsilastirma => 'Dönem Karşılaştırma',
        };
    }

    public function ikon(): string
    {
        return match ($this) {
            self::Kasa     => 'bi-wallet2',
            self::Anket    => 'bi-emoji-heart-eyes',
            self::Randevu  => 'bi-clock-history',
            self::Cark     => 'bi-stars',
            self::Akis     => 'bi-activity',
            self::Personel => 'bi-person-workspace',
            self::Karsilastirma => 'bi-graph-up-arrow',
        };
    }

    /** Stüdyo modunda (FizyoFLY) gizlenen kart. */
    public function studyoGizle(): bool
    {
        return $this === self::Cark || $this === self::Karsilastirma;
    }

    /** @return array<string,string> RaporDonemi kodu => kartin dugme etiketi (eski ekranla ayni) */
    private function donemEtiketleri(): array
    {
        return match ($this) {
            self::Kasa          => ['bugun' => 'Günlük', 'son7' => 'Son 7 gün', 'son30' => 'Son 30 gün'],
            self::Randevu       => ['bugun' => 'Günlük', 'son7' => '7g', 'son30' => '30g'],
            self::Anket, self::Cark => ['son7' => '7g', 'son30' => '30g', 'son90' => '90g'],
            self::Karsilastirma => ['bugun' => 'Günlük', 'hafta' => 'Haftalık', 'ay' => 'Aylık', 'yil' => 'Yıllık'],
            default             => [],
        };
    }

    /** @return array<string> RaporDonemi kodlari (bos = donemsiz, hep bugun) */
    public function donemler(): array
    {
        return array_keys($this->donemEtiketleri());
    }

    /** @return array<int,array{kod:string,ad:string}> acilis cevabindaki dugmeler */
    public function donemSecenekleri(): array
    {
        return array_map(fn ($kod, $ad) => ['kod' => $kod, 'ad' => $ad], $this->donemler(), $this->donemEtiketleri());
    }

    public function varsayilanDonem(): ?string
    {
        return match ($this) {
            self::Kasa, self::Anket, self::Cark => 'son30',
            self::Randevu                       => 'bugun',
            self::Karsilastirma                 => 'ay',
            default                             => null,
        };
    }
}
