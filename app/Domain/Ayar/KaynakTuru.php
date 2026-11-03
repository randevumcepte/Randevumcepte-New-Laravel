<?php

namespace App\Domain\Ayar;

/**
 * Takvim kaynagi turleri (cihaz / oda) — ayni semayi paylasan iki tablo ailesinin SAF tanimi.
 *
 *   <tablo>        (id, salon_id, <ad_kolonu>, durum 1=musait, aciklama, aktifmi, takvim_sirasi)
 *   <renk_tablosu> (salon_id, <fk>, renk_id 1..10) — takvim boyamasi (RandevuOkumaServisi okur)
 *
 * Eski panelde iki kopya kod (cihaz_* / oda_*) olan liste, durum, sil ve siralama burada tek
 * tanimla calisir; tur'e ozel kisimlar (cihaz saatleri, oda personel/hizmetleri) servislerde.
 */
enum KaynakTuru: string
{
    case Cihaz = 'cihaz';
    case Oda = 'oda';

    /** Takvim renk_id dongusu (renk_duzenleri 10'lu palet). */
    public const RENK_SAYISI = 10;

    public function tablo(): string
    {
        return $this === self::Cihaz ? 'cihazlar' : 'odalar';
    }

    public function adKolonu(): string
    {
        return $this->value.'_adi';
    }

    public function renkTablosu(): string
    {
        return $this === self::Cihaz ? 'salon_cihaz_renkleri' : 'salon_oda_renkleri';
    }

    public function fk(): string
    {
        return $this->value.'_id';
    }

    /** AyarSozluk sekme kodu (yetki anahtari buradan). */
    public function sekme(): string
    {
        return $this === self::Cihaz ? 'cihazlar' : 'odalar';
    }

    public function etiket(): string
    {
        return $this === self::Cihaz ? 'Cihaz' : 'Oda';
    }

    /** Son kullanilan renk_id'den sonraki (10'dan sonra 1'e doner) — eskiyle ayni. */
    public static function sonrakiRenk(?int $sonRenkId): int
    {
        return $sonRenkId === null || $sonRenkId >= self::RENK_SAYISI ? 1 : $sonRenkId + 1;
    }
}
