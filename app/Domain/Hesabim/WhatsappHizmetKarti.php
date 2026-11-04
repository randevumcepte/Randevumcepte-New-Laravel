<?php

namespace App\Domain\Hesabim;

use App\Domain\Whatsapp\WhatsappDurumu;

/**
 * WhatsApp hizmet kartı kuralı (saf; DB/saat bilmez).
 *
 *  - Ücretli paket (pro/premium): whatsapp_paket_* kolonlarından.
 *  - Diğerleri: salon başına ücretsiz deneme (eski Salonlar::whatsappPromoBilgisi). Bitişi yok ya da
 *    bozuksa global başlangıcın bir önceki günü — süre dolmuş sayımı WhatsappDurumu ile aynı kural.
 */
final class WhatsappHizmetKarti
{
    private const UCRETLI_PAKETLER = ['pro', 'premium'];

    private const AD = 'WhatsApp Hatırlatma';

    private const ACIKLAMA = 'Otomatik WhatsApp ile randevu hatırlatma';

    /** @param object $salon salonlar satırı; $bugun 'Y-m-d' */
    public static function olustur(object $salon, string $bugun, string $iletisimTel): HizmetKarti
    {
        return in_array((string) ($salon->whatsapp_paket ?? ''), self::UCRETLI_PAKETLER, true)
            ? self::ucretli($salon, $bugun)
            : self::ucretsizDonem($salon, $bugun, $iletisimTel);
    }

    private static function ucretli(object $salon, string $bugun): HizmetKarti
    {
        $bitis = self::tarih($salon->whatsapp_paket_bitis ?? null);
        $deneme = (bool) ($salon->whatsapp_paket_deneme ?? false);

        return new HizmetKarti(
            kod: 'whatsapp',
            ad: self::AD,
            aciklama: $deneme ? 'Ücretsiz deneme süresi aktif' : self::ACIKLAMA,
            icon: 'fa-whatsapp',
            ikon: 'bi-whatsapp',
            renk: 'yesil',
            aktif: (bool) ($salon->whatsapp_aktif ?? false),
            periyot: ($salon->whatsapp_paket_periyot ?? null) === 'yillik' ? 'Yıllık' : 'Aylık',
            baslangic: self::tarih($salon->whatsapp_paket_baslangic ?? null),
            bitis: $bitis,
            kalanGun: $bitis ? max(0, self::gunFarki($bugun, $bitis)) : null,
            deneme: $deneme,
        );
    }

    private static function ucretsizDonem(object $salon, string $bugun, string $iletisimTel): HizmetKarti
    {
        $denemeBitis = $salon->whatsapp_deneme_bitis ?? null;
        $bitis = self::tarih($denemeBitis)
            ?? (new \DateTimeImmutable(WhatsappDurumu::KONTOR_BASLANGIC))->modify('-1 day')->format('Y-m-d');
        $doldu = WhatsappDurumu::kontorluDonemMi($denemeBitis, $bugun);

        return new HizmetKarti(
            kod: 'whatsapp',
            ad: self::AD,
            aciklama: $doldu ? 'Ücretsiz süre doldu — devam için iletişime geçin: '.$iletisimTel : self::ACIKLAMA.' · 2 ay ücretsiz',
            icon: 'fa-whatsapp',
            ikon: 'bi-whatsapp',
            renk: 'yesil',
            aktif: ! $doldu,
            periyot: $doldu ? 'Ücretsiz süre bitti' : '2 Ay Ücretsiz',
            bitis: $bitis,
            kalanGun: max(0, self::gunFarki($bugun, $bitis)),
            deneme: ! $doldu,
            denemeEtiket: '2 Ay Ücretsiz',
        );
    }

    /** 'Y-m-d' ya da null (boş / 0000-00-00). */
    private static function tarih(?string $deger): ?string
    {
        $t = substr((string) $deger, 0, 10);

        return $t !== '' && $t !== '0000-00-00' ? $t : null;
    }

    private static function gunFarki(string $bas, string $bit): int
    {
        return (int) (new \DateTimeImmutable($bas))->diff(new \DateTimeImmutable($bit))->format('%r%a');
    }
}
