<?php

namespace App\Domain\Kampanya;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Müşteri ana sayfasındaki "Kampanya ve Duyurular" satırı (saf, DB'siz). Eski musteriKampanyalari
 * ile aynı alanlar; iç `[gap:KEY]` etiketi ve otomatik öneri notu müşteriye gönderilmez.
 */
final class MusteriKampanyasi
{
    /** @return array<string, mixed> */
    public static function satirdan(object $k, DateTimeInterface $simdi): array
    {
        $bitis = $k->kampanya_bitis_tarihi ? (string) $k->kampanya_bitis_tarihi : null;

        return [
            'id'          => (int) $k->id,
            'baslik'      => $k->kampanya_baslik,
            'aciklama'    => $k->kampanya_aciklama,
            'indirim'     => SaatBosluguKampanyasi::indirim((string) $k->kampanya_aciklama, (string) $k->kampanya_baslik),
            'tip'         => SaatBosluguKampanyasi::etiketliMi($k->kampanya_detay) ? 'gap' : 'genel',
            'fiyat'       => self::tutar($k->kampanya_fiyat),
            'normalFiyat' => self::tutar($k->hizmet_normal_fiyat),
            'bitisTarihi' => $bitis ? (new DateTimeImmutable($bitis))->format('d.m.Y') : null,
            'kalanGun'    => $bitis ? SaatBosluguKampanyasi::kalanGun($bitis, $simdi) : null,
        ];
    }

    private static function tutar(mixed $deger): ?float
    {
        return $deger !== null && $deger !== '' ? (float) $deger : null;
    }
}
