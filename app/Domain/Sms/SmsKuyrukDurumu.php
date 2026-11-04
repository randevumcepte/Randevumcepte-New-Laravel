<?php

namespace App\Domain\Sms;

/**
 * v2 SMS kuyruğu durumları (v2_bildirim_kuyrugu.durum, tur='sms_toplu').
 *
 * 4 ve 5 BİLEREK ayrı: eski monolitin `v2:bildirim-isle` komutu yalnız 0 alır ve 5 dk'lık 3'leri 0'a
 * çevirir. Bu değerler onun görüş alanı dışında kalır; kayıtları yalnız `sms:kuyruk-isle` işler.
 */
final class SmsKuyrukDurumu
{
    public const GONDERILDI = 1;

    public const HATA = 2;

    public const BEKLIYOR = 4;

    public const ISLENIYOR = 5;

    /** @return array{durum:string, etiket:string, tamamlandi:bool} */
    public static function sunum(int $durum): array
    {
        return match ($durum) {
            self::GONDERILDI => ['durum' => 'gonderildi', 'etiket' => 'Sağlayıcıya iletildi', 'tamamlandi' => true],
            self::HATA       => ['durum' => 'hata', 'etiket' => 'Gönderilemedi', 'tamamlandi' => true],
            self::ISLENIYOR  => ['durum' => 'isleniyor', 'etiket' => 'Gönderiliyor', 'tamamlandi' => false],
            default          => ['durum' => 'bekliyor', 'etiket' => 'Kuyrukta', 'tamamlandi' => false],
        };
    }
}
