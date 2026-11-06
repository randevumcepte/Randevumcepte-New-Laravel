<?php

namespace App\Services\SistemYonetim\Entegrasyon;

use App\Domain\Whatsapp\BaglantiDurumu;
use App\Domain\Whatsapp\GonderimDurumu;

/** WhatsApp Yonetim ekraninin secenekleri: paket, periyot, deneme suresi, durum sozlukleri. Ekranda sabit liste yok. */
final class WhatsappPaketKatalogu
{
    public const BASLANGIC = 'baslangic';

    public const PAKETLER = [
        self::BASLANGIC => ['ad' => 'Başlangıç (Aktif Değil)', 'secim_ad' => 'Başlangıç (Pasif)', 'secilir' => true],
        'pro'           => ['ad' => 'WhatsApp Hatırlatma', 'secim_ad' => 'WhatsApp Hatırlatma', 'secilir' => true],
        'premium'       => ['ad' => 'WhatsApp Hatırlatma', 'secim_ad' => 'WhatsApp Hatırlatma', 'secilir' => false],
    ];

    public const DENEME_PAKETLERI = ['pro', 'premium'];

    public const PERIYOTLAR = ['aylik' => 'Aylık', 'yillik' => 'Yıllık'];

    public const DENEME_VARSAYILAN_PAKET = 'pro';

    public const DENEME_VARSAYILAN_GUN = 90;

    public const DENEME_MIN_GUN = 1;

    public const DENEME_MAKS_GUN = 365;

    private const HIZLI_SURELER = [
        ['gun' => 30, 'etiket' => '30 gün', 'onerilen' => false],
        ['gun' => 60, 'etiket' => '2 ay', 'onerilen' => false],
        ['gun' => 90, 'etiket' => '3 ay (önerilen)', 'onerilen' => true],
        ['gun' => 180, 'etiket' => '6 ay', 'onerilen' => false],
        ['gun' => 365, 'etiket' => '1 yıl', 'onerilen' => false],
    ];

    public static function secenekler(): array
    {
        return [
            'paketler'         => array_values(array_map(fn ($k, $p) => ['kod' => $k] + $p, array_keys(self::PAKETLER), self::PAKETLER)),
            'periyotlar'       => array_map(fn ($k, $ad) => ['kod' => $k, 'ad' => $ad], array_keys(self::PERIYOTLAR), self::PERIYOTLAR),
            'deneme'           => [
                'paket'  => self::DENEME_VARSAYILAN_PAKET,
                'gun'    => self::DENEME_VARSAYILAN_GUN,
                'min'    => self::DENEME_MIN_GUN,
                'maks'   => self::DENEME_MAKS_GUN,
                'hizli'  => self::HIZLI_SURELER,
            ],
            'log_durumlari'    => GonderimDurumu::sozluk(),
            'salon_durumlari'  => BaglantiDurumu::sozluk(),
        ];
    }
}
