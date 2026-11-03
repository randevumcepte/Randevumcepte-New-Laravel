<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\CakismaException;
use App\Http\Requests\Stok\AlisGirisiIstegi;
use App\Http\Requests\Stok\HizliSatisIstegi;
use App\Http\Requests\Stok\ManuelHareketIstegi;
use App\Http\Requests\Stok\SayimIstegi;
use App\Http\Requests\Stok\TransferIstegi;
use App\Models\Personeller;
use App\Services\Stok\HizliSatisServisi;
use App\Services\Stok\StokIslemServisi;
use App\Support\ApiCevap;

/**
 * Stok ISLEM uclari (ince controller) — hepsi yazma; kill-switch arkasinda.
 *   POST /stok/hareketler   (manuel: fire/iade/sarf/manuel)  urun.stok_giris
 *   POST /stok/alis         (mal kabul)                      urun.stok_giris
 *   POST /stok/transfer     (depolar arasi)                  urun.stok_giris
 *   POST /stok/sayim                                          urun.stok_sayim
 *   POST /stok/hizli-satis  (adisyon + stok + pesin tahsilat) urun.sat (+ satis.tahsilat_al)
 */
class StokIslemController extends StokTabanController
{
    public function __construct(private StokIslemServisi $islem) {}

    public function manuel(ManuelHareketIstegi $request)
    {
        [$uid, $salonId] = $this->salonYetki($request, 'urun.stok_giris');
        $this->yazilabilir();

        return ApiCevap::basarili($this->islem->manuel($salonId, $request->validated(), $uid), 'Stok hareketi kaydedildi.');
    }

    public function alis(AlisGirisiIstegi $request)
    {
        [$uid, $salonId] = $this->salonYetki($request, 'urun.stok_giris');
        $this->yazilabilir();
        $sonuc = $this->islem->alis($salonId, $request->validated(), $uid);

        return ApiCevap::basarili($sonuc, "Alış kaydedildi ({$sonuc['kalem_sayisi']} kalem).");
    }

    public function transfer(TransferIstegi $request)
    {
        [$uid, $salonId] = $this->salonYetki($request, 'urun.stok_giris');
        $this->yazilabilir();

        return ApiCevap::basarili($this->islem->transfer($salonId, $request->validated(), $uid), 'Transfer tamamlandı.');
    }

    public function sayim(SayimIstegi $request)
    {
        [$uid, $salonId] = $this->salonYetki($request, 'urun.stok_sayim');
        $this->yazilabilir();
        $sonuc = $this->islem->sayim($salonId, $request->validated(), $uid);

        return ApiCevap::basarili($sonuc, "{$sonuc['sayilan_kalem']} kalemde düzeltme uygulandı.");
    }

    public function hizliSatis(HizliSatisIstegi $request, HizliSatisServisi $satis)
    {
        [$uid, $salonId] = $this->salonYetki($request, 'urun.sat');
        $v = $request->validated();
        if ($v['tahsilat_yap']) {
            $this->kapi()->denetle($uid, $salonId, 'satis.tahsilat_al');
        }
        $this->yazilabilir();
        if ($v['tahsilat_yap'] && ! config('tahsilat.yazma_aktif')) {
            throw new CakismaException('Peşin tahsilat v2 yazma şu an kapalı — "Ödemeyi şimdi al" seçimini kaldırın.');
        }

        $sonuc = $satis->sat($salonId, $v, [
            'yetkili_id'            => $uid,
            'olusturan_personel_id' => Personeller::where('salon_id', $salonId)->where('yetkili_id', $uid)->value('id'),
        ]);
        $mesaj = $sonuc['replay'] ? 'Bu satış zaten kaydedilmişti.' : ($sonuc['tahsilat_id'] ? 'Satış + tahsilat tamam.' : 'Satış kaydedildi (ödeme bekliyor).');

        return ApiCevap::basarili($sonuc, $mesaj);
    }
}
