<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Bildirim\CihazSahibi;
use App\Http\Requests\Bildirim\CihazKaydetIstegi;
use App\Services\Bildirim\BildirimKutusu;
use App\Services\Bildirim\BildirimSorgusu;
use App\Services\Bildirim\BildirimYazServisi;
use App\Services\Bildirim\CihazKayitServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Personel ve müşteri bildirim uçlarının ORTAK eylemleri. İki controller yalnız kimliği (kutu, cihaz
 * sahibi) ve yazma kapısını verir; davranış ve cevap biçimi tek yerde kalır.
 */
trait BildirimKutusuUclari
{
    abstract protected function kutu(Request $request): BildirimKutusu;

    abstract protected function cihazSahibi(Request $request): CihazSahibi;

    /** Yazma öncesi kapı (personelde kill-switch; müşteri eylemi bayrağa bağlı değil). */
    abstract protected function yazmaKapisi(): void;

    public function liste(Request $request, BildirimSorgusu $sorgu)
    {
        $liste = ListeIstegi::istekten($request, 30)->dizi();

        return ApiCevap::basarili($sorgu->listele($this->kutu($request), $liste, $request->input('filtre') === 'okunmamis'));
    }

    public function sayac(Request $request, BildirimSorgusu $sorgu)
    {
        return ApiCevap::basarili($sorgu->sayac($this->kutu($request)));
    }

    public function okundu(Request $request, BildirimYazServisi $yaz, int $id)
    {
        $this->yazmaKapisi();

        return ApiCevap::basarili($yaz->okundu($this->kutu($request), $id));
    }

    public function tumunuOku(Request $request, BildirimYazServisi $yaz)
    {
        $this->yazmaKapisi();

        return ApiCevap::basarili($yaz->tumunuOku($this->kutu($request)), 'Tüm bildirimler okundu olarak işaretlendi');
    }

    public function cihazKaydet(CihazKaydetIstegi $request, CihazKayitServisi $cihazlar)
    {
        $this->yazmaKapisi();
        $v = $request->validated();
        $id = $cihazlar->kaydet($this->cihazSahibi($request), $v['token'], $v['platform'], $v['cihaz'] ?? null, $v['app_bundle'] ?? null);

        return ApiCevap::basarili(['id' => $id]);
    }

    /** Çıkıştan ÖNCE çağrılmalı: çıkış token'ı iptal eder, sonraki istek 401 olur. */
    public function cihazSil(Request $request, CihazKayitServisi $cihazlar)
    {
        $this->yazmaKapisi();
        $v = $request->validate(['token' => ['required', 'string', 'max:512']]);

        return ApiCevap::basarili(['pasif' => $cihazlar->pasiflestir($this->cihazSahibi($request), $v['token'])]);
    }
}
