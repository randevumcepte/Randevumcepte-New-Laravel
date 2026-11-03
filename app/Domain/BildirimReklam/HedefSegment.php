<?php

namespace App\Domain\BildirimReklam;

/**
 * Push hedef kitlesi segment tipi (bildirim_reklamlari.hedef_kosul JSON'undaki 'tip').
 * Her segmentin TEK parametresi vardır (alan()); JSON anahtarı eskiyle aynıdır ki eski
 * panel, eski push job'u ve Flutter v1 aynı kaydı okumaya devam etsin.
 */
enum HedefSegment: string
{
    case KISI = 'kisi';
    case GELMEYEN = 'gelmeyen';
    case DOGUM_GUNU = 'dogum_gunu';
    case HIZMET = 'hizmet';
    case URUN = 'urun';
    case CINSIYET = 'cinsiyet';

    /** Varsayılan "gelmeyen" gün eşiği (eski: segment_gun ?: 60). */
    public const VARSAYILAN_GUN = 60;

    public function etiket(): string
    {
        return match ($this) {
            self::KISI => 'Belirli kişi (test için)',
            self::GELMEYEN => 'Uzun süredir gelmeyenler',
            self::DOGUM_GUNU => 'Doğum günü bu ay olanlar',
            self::HIZMET => 'Belirli hizmeti alanlar',
            self::URUN => 'Belirli ürünü alanlar',
            self::CINSIYET => 'Cinsiyete göre',
        };
    }

    /** hedef_kosul içindeki parametre anahtarı (yoksa null). */
    public function alan(): ?string
    {
        return match ($this) {
            self::KISI => 'user_id',
            self::GELMEYEN => 'gun',
            self::DOGUM_GUNU => null,
            self::HIZMET => 'hizmet_id',
            self::URUN => 'urun_id',
            self::CINSIYET => 'cinsiyet',
        };
    }

    /** @return list<array{kod:string, ad:string, alan:?string}> */
    public static function secenekler(): array
    {
        return array_map(fn (self $s) => ['kod' => $s->value, 'ad' => $s->etiket(), 'alan' => $s->alan()], self::cases());
    }

    /** @return list<string> */
    public static function kodlar(): array
    {
        return array_column(self::cases(), 'value');
    }
}
