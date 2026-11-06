<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Domain\SistemYonetim\SistemYetki;
use App\Services\SistemYonetim\Icerik\PaketSiparisServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 *   GET  /sistem/paket-siparis + ListeIstegi (sirala: tarih|salon|sms_adet|tutar|odeme|yukleme|fatura) → sayfa + {bekleyen, yazabilir}
 *   POST /sistem/paket-siparis/{id}/yuklendi
 */
class PaketSiparisController extends DestekTabanController
{
    public function __construct(private PaketSiparisServisi $servis) {}

    public function liste(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 25, array_keys(PaketSiparisServisi::SIRALAMA), 'tarih', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->listele($liste) + ['yazabilir' => $this->izinli(SistemYetki::ISLEM)]);
    }

    public function yuklendi(int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->yuklendiIsaretle($id, $this->yonetici());

        return ApiCevap::basarili(null, 'Sipariş "yüklendi" olarak işaretlendi.');
    }
}
