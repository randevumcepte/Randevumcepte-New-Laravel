<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Anket\AnketManuelGonderIstegi;
use App\Http\Requests\Anket\AnketSablonKaydetIstegi;
use App\Services\Anket\AnketGonderServisi;
use App\Services\Anket\AnketSablonSorgusu;
use App\Services\Anket\AnketSablonYazServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Anket şablonları.
 *   GET    /anket/sablonlar?salon_id= + ListeIstegi     POST /anket/sablonlar
 *   GET    /anket/sablonlar/{id}                        PATCH /anket/sablonlar/{id}
 *   DELETE /anket/sablonlar/{id}                        → {silindi, pasiflestirildi, kullanim}
 *   POST   /anket/sablonlar/{id}/gonder {telefon, ad_soyad?, user_id?, randevu_id?, personel_id?}
 */
class AnketSablonController extends AnketTabanController
{
    private const BULUNAMADI = 'Şablon bulunamadı';

    public function __construct(private AnketSablonSorgusu $sorgu) {}

    public function liste(Request $request)
    {
        [, $salonId] = $this->salonDenetle($request, self::YETKI);
        $liste = ListeIstegi::istekten($request, 25, array_keys(AnketSablonSorgusu::SIRALAMA), null, 'asc')->dizi();

        return ApiCevap::basarili($this->sorgu->listele($salonId, $liste));
    }

    public function goster(int $id)
    {
        $this->anketKaydi($this->sorgu->salonId($id), self::BULUNAMADI);

        return ApiCevap::basarili($this->sorgu->getir($id));
    }

    public function ekle(AnketSablonKaydetIstegi $request, AnketSablonYazServisi $yaz)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $this->yazmaAcikMi();
        $id = $yaz->kaydet($salonId, null, $request->validated(), $uid);

        return ApiCevap::basarili($this->sorgu->getir($id), 'Şablon kaydedildi', 201);
    }

    public function guncelle(AnketSablonKaydetIstegi $request, AnketSablonYazServisi $yaz, int $id)
    {
        [$uid, $salonId] = $this->anketKaydi($this->sorgu->salonId($id), self::BULUNAMADI);
        $this->yazmaAcikMi();
        $yaz->kaydet($salonId, $id, $request->validated(), $uid);

        return ApiCevap::basarili($this->sorgu->getir($id), 'Şablon kaydedildi');
    }

    public function sil(AnketSablonYazServisi $yaz, int $id)
    {
        [$uid, $salonId] = $this->anketKaydi($this->sorgu->salonId($id), self::BULUNAMADI);
        $this->yazmaAcikMi();
        $sonuc = $yaz->sil($id, $salonId, $uid);

        return ApiCevap::basarili($sonuc, $sonuc['pasiflestirildi']
            ? "Şablon {$sonuc['kullanim']} anket gönderiminde kullanılmış, pasifleştirildi."
            : 'Şablon silindi');
    }

    public function gonder(AnketManuelGonderIstegi $request, AnketGonderServisi $gonderici, int $id)
    {
        [$uid, $salonId] = $this->anketKaydi($this->sorgu->salonId($id), self::BULUNAMADI);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($gonderici->manuel($salonId, $id, $request->validated(), $uid), 'Anket gönderim kuyruğuna alındı', 201);
    }
}
