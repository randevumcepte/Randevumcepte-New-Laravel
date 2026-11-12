<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\MusteriHesap\MusteriYorumYonetimServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Isletme yorum moderasyonu (yalniz mobil; web karsiligi yok).
 *
 *   GET    /musteri-yorum?salon_id=   {yorumlar[], toplam, bildirilen_sayi}  imzali mobil token'la salonun her uyesi
 *   DELETE /musteri-yorum/{id}        salon yorumdan okunur; kapsam + 'pazarlama.anket_yonet' (Anket / degerlendirme)
 */
class MusteriYorumYonetimController extends V2Controller
{
    protected ?string $yazmaAlani = 'musteri';

    /** Yorum silme: musteri degerlendirmeleri anket/degerlendirme yetkisindedir (YetkiSabitleri). */
    private const SILME_YETKI = 'pazarlama.anket_yonet';

    public function __construct(private MusteriYorumYonetimServisi $yorum) {}

    public function liste(Request $r)
    {
        $this->mobilOlmali($r);
        [, $salonId] = $this->salonDenetle($r, []);

        return ApiCevap::basarili($this->yorum->liste($salonId));
    }

    public function sil(Request $r, GuvenlikKapisi $kapi, int $id)
    {
        $this->mobilOlmali($r);
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu($this->yorum->salonu($id), 'Yorum bulunamadi');
        $kapi->denetle($uid, $salonId, self::SILME_YETKI);
        $this->yazmaAcikMi();
        $this->yorum->sil($salonId, $id, $uid);

        return ApiCevap::basarili(null, 'Yorum silindi');
    }
}
