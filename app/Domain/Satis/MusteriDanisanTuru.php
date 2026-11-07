<?php

namespace App\Domain\Satis;

/**
 * Musteri turu (0 yeni, 1 mevcut, 2 sadik) — SAF. Eski `ApiController@musteri_danisan_turunu_getir`
 * ile ayni: 3'ten cok tahsilat VE son tahsilat kaydi + 90 gun, simdi + 90 gunden once ise 2.
 */
final class MusteriDanisanTuru
{
    public static function hesapla(int $tahsilatSayisi, ?string $sonTahsilatOlusturma, int $simdi): int
    {
        if ($tahsilatSayisi > 3
            && date('Y-m-d H:i:s', strtotime('+90 days', (int) strtotime((string) $sonTahsilatOlusturma)))
                < date('Y-m-d H:i:s', strtotime('+90 days', $simdi))) {
            return 2;
        }

        return $tahsilatSayisi === 0 ? 0 : 1;
    }
}
