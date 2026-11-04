<?php

namespace App\Domain\Sms;

/**
 * Mesaj uzunluğu → SMS adedi (ücretlendirme). Eşikler eski blade countChar + Flutter KarakterSayaci
 * ile aynı; istemciler eşikleri /sms/acilis'ten alır (kural istemcide kopyalanmaz).
 */
final class MesajUzunlugu
{
    /** Sıradaki eşik = o adedin üst sınırı (155 karaktere kadar 1 SMS, 292'ye kadar 2 SMS …). */
    public const ESIKLER = [155, 292, 439, 587, 735, 882];

    /** Son eşikten sonra her ek parçanın kapasitesi. */
    private const EK_PARCA = 147;

    public static function parca(string $mesaj): int
    {
        $uzunluk = mb_strlen($mesaj);
        foreach (self::ESIKLER as $i => $esik) {
            if ($uzunluk <= $esik) {
                return $i + 1;
            }
        }
        $sonEsik = self::ESIKLER[count(self::ESIKLER) - 1];

        return count(self::ESIKLER) + (int) ceil(($uzunluk - $sonEsik) / self::EK_PARCA);
    }
}
