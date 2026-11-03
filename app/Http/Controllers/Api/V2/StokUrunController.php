<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Stok\UrunKaydetIstegi;
use App\Services\Stok\StokRaporServisi;
use App\Services\Stok\UrunServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Stok — URUN uclari (ince controller).
 *   GET    /stok/urunler?salon_id=&kategori_id=&tip=&seviye=&depo_id=&tumu= + ListeIstegi (page, per_page, ara, sirala, yon)
 *   GET    /stok/urunler/barkod?salon_id=&barkod=
 *   GET    /stok/urunler/{id}                 (+ depo_stoklari)
 *   GET    /stok/urunler/{id}/satis-raporu?baslangic=&bitis=
 *   POST   /stok/urunler  {salon_id, ...}     PATCH /stok/urunler/{id}     DELETE /stok/urunler/{id}
 */
class StokUrunController extends StokTabanController
{
    public function __construct(private UrunServisi $urun) {}

    public function liste(Request $request)
    {
        [, $salonId] = $this->salonYetki($request, self::URUN_OKU);
        $liste = ListeIstegi::istekten($request, 25, UrunServisi::SIRALANABILIR, 'urun_adi', 'asc')->dizi();

        return ApiCevap::basarili($this->urun->listele($salonId, [
            'kategori_id' => $request->input('kategori_id'),
            'tip'         => $request->input('tip'),
            'seviye'      => $request->input('seviye'),
            'depo_id'     => $request->input('depo_id'),
            'tumu'        => $request->boolean('tumu'),
        ], $liste));
    }

    public function barkod(Request $request)
    {
        [, $salonId] = $this->salonYetki($request, self::URUN_OKU);

        return ApiCevap::basarili($this->urun->barkodla($salonId, (string) $request->input('barkod')));
    }

    public function goster(int $id)
    {
        $this->kayitYetki('urun', $id, self::URUN_OKU);

        return ApiCevap::basarili($this->urun->getir($id));
    }

    public function satisRaporu(Request $request, int $id, StokRaporServisi $rapor)
    {
        $this->kayitYetki('urun', $id, self::GORUNTULE);

        return ApiCevap::basarili($rapor->urunSatisRaporu($id, $request->input('baslangic'), $request->input('bitis')));
    }

    public function ekle(UrunKaydetIstegi $request)
    {
        [$uid, $salonId] = $this->salonYetki($request, 'urun.tanim_olustur');
        $this->yazilabilir();

        return ApiCevap::basarili($this->urun->kaydet($salonId, null, $request->validated(), $uid), 'Ürün eklendi.', 201);
    }

    public function guncelle(UrunKaydetIstegi $request, int $id)
    {
        [$uid, $salonId] = $this->kayitYetki('urun', $id, 'urun.tanim_olustur');
        $this->yazilabilir();

        return ApiCevap::basarili($this->urun->kaydet($salonId, $id, $request->validated(), $uid), 'Ürün güncellendi.');
    }

    public function sil(int $id)
    {
        [$uid] = $this->kayitYetki('urun', $id, 'urun.tanim_olustur');
        $this->yazilabilir();

        return ApiCevap::basarili($this->urun->sil($id, $uid), 'Ürün silindi.');
    }
}
