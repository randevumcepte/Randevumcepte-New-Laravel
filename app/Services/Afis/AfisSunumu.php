<?php

namespace App\Services\Afis;

use App\Domain\Afis\AfisIcerigi;
use App\Domain\Afis\MagazaLinkleri;

/**
 * GET /api/v2/uygulama-afisi yanıt biçimi — web ve Flutter AYNI alanları okur.
 * Ekranın hangi bölümü göstereceği (afiş / uyarı / paylaşım linki) yalnız bu bayraklardan
 * türetilir; istemci kural yazmaz.
 */
final class AfisSunumu
{
    public const PAYLASIM_ONEKI = 'Uygulamamızı indirmek için: ';

    /** @return array<string, mixed> */
    public static function ozet(AfisIcerigi $icerik, MagazaLinkleri $linkler): array
    {
        $paylasim = self::PAYLASIM_ONEKI.$icerik->indirLinki;
        $sorgu = '?salon_id='.$icerik->salonId;

        return [
            'salon'          => ['id' => $icerik->salonId, 'ad' => $icerik->ad],
            'afis_hazir'     => $linkler->afisHazir(),      // PDF + önizleme açık
            'link_hazir'     => $linkler->herhangiVar(),    // paylaşım linki bölümü açık
            'magazalar'      => $linkler->tanimlilar(),
            'eksik_magazalar'=> self::eksikler($linkler),
            'indir_linki'    => $icerik->indirLinki,
            'paylasim_metni' => $paylasim,
            'whatsapp_url'   => 'https://api.whatsapp.com/send?text='.rawurlencode($paylasim),
            'dosya_adi'      => $icerik->dosyaAdi('pdf'),
            'uclar'          => [
                'onizleme' => '/api/v2/uygulama-afisi/onizleme'.$sorgu,
                'pdf'      => '/api/v2/uygulama-afisi/pdf'.$sorgu,
            ],
        ];
    }

    /** @return list<string> afiş için eksik mağaza adları (uyarı metni için) */
    private static function eksikler(MagazaLinkleri $linkler): array
    {
        $t = $linkler->tanimlilar();

        return array_values(array_filter([
            $t['ios'] ? null : 'App Store',
            $t['android'] ? null : 'Google Play',
        ]));
    }
}
