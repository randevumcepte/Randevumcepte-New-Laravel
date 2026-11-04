<?php

namespace App\Domain\CagriMerkezi;

/**
 * Çağrı Merkezi EYLEM KAYIT DEFTERİ — bir bölümü/işlemi kimin yapabileceği TEK yerde (web + Flutter).
 * Sekmeler (CagriMerkeziSekmeleri) ve uçlar (CagriMerkeziTabanController, servisler) buradan geçer.
 *
 * YENİ YETKİ ANAHTARI BAĞLAMAK:
 *   1) Anahtarı YetkiSabitleri'ne ekle (tanım + şablonlar; eski monolitle parite notuna bak)
 *   2) İlgili eylemin anahtarlar() satırına yaz (OR: biri yeterli)
 * Uç, sekme ve bayrak (erisim.yetkiler) ek kod gerektirmez. Boş liste = anahtar aranmaz.
 */
enum CagriMerkeziEylemi: string
{
    case Calisma = 'calisma';                 // kendi listeleri, kuyruk, görüşme sonucu
    case AramaRandevu = 'arama_randevu';      // takvim, ajanda, hatırlatma, erteleme
    case CagriKayitlari = 'cagri_kayitlari';  // santral çağrı geçmişi + ses kayıtları
    case Arama = 'arama';
    case Whatsapp = 'whatsapp';
    case Satis = 'satis';                     // Telefonda Satış (eski cagri_hizli_satis)
    case FiyatDegistir = 'fiyat_degistir';    // katalogdan farklı satış fiyatı
    case Anket = 'anket';                     // Memnuniyet anketi gönder
    case OnGorusme = 'ongorusme';             // Ön görüşme randevusu oluştur
    case Performans = 'performans';
    case ListeYonet = 'liste_yonet';          // liste oluşturma, atama, taşıma, CSV, arşiv
    case AyarYonet = 'ayar_yonet';            // görüşme scriptleri + sonuç kategorileri

    /** Personel (rol 5) hesaplarına kapalı mı? */
    public function yalnizYonetici(): bool
    {
        return match ($this) {
            self::Performans, self::ListeYonet, self::AyarYonet => true,
            default => false,
        };
    }

    /** @return array<string> gereken yetki anahtarları (OR); boş = anahtar aranmaz */
    public function anahtarlar(): array
    {
        return match ($this) {
            self::Satis         => ['satis.tahsilat_al'],
            self::FiyatDegistir => ['satis.indirim_uygula'],
            self::Anket         => ['pazarlama.anket_yonet'],
            self::OnGorusme     => ['gorusme.ekle_duzenle'],
            default             => [],
        };
    }

    public function redMesaji(): string
    {
        return match ($this) {
            self::Satis => 'Satış için yetkiniz yok.',
            self::Anket => 'Anket gönderme yetkiniz yok.',
            default     => $this->yalnizYonetici() ? 'Bu bölüm personel hesaplarına kapalıdır' : 'Bu işlem için yetkiniz yok',
        };
    }
}
