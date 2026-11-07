<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Http\Requests\Ajanda\AjandaIstegi;
use App\Services\Ajanda\AjandaServisi;
use App\Services\Musteri\PortfoyKapsami;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Ajanda notlari: salonun her uyesi kendi notlarini yonetir, ozel yetki aranmaz (eski menu de aramaz).
 *   GET    /ajanda?salon_id=&ara=&page=
 *   POST   /ajanda {salon_id, baslik, icerik?, tarih, saat, hatirlatma?, hatirlatma_saati?}
 *   PATCH  /ajanda/{id}
 *   DELETE /ajanda/{id}
 *   POST   /ajanda/{id}/okundu
 */
class AjandaController extends V2Controller
{
    public function __construct(
        private AjandaServisi $ajanda,
        private PortfoyKapsami $portfoy,
        private GuvenlikKapisi $kapi,
    ) {}

    public function liste(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, []);
        $istek = ListeIstegi::istekten($request);

        return ApiCevap::basarili($this->ajanda->liste($salonId, $this->portfoy->personelId($uid, $salonId), $istek));
    }

    public function ekle(AjandaIstegi $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, []);
        $personelId = $this->portfoy->personelId($uid, $salonId)
            ?? throw new GecersizIslemException('Bu işletmede personel kaydınız yok');

        return ApiCevap::basarili($this->ajanda->ekle($salonId, $personelId, $uid, $request->validated()), 'Notunuz kaydedildi.', 201);
    }

    public function guncelle(AjandaIstegi $request, int $id)
    {
        [$uid, $salonId, $personelId] = $this->notSalonu($id);

        return ApiCevap::basarili($this->ajanda->guncelle($id, $salonId, $personelId, $uid, $request->validated()), 'Notunuz kaydedildi.');
    }

    public function okundu(int $id)
    {
        [$uid, $salonId, $personelId] = $this->notSalonu($id);
        $this->ajanda->okundu($id, $salonId, $personelId, $uid);

        return ApiCevap::basarili(null, 'Not okundu işaretlendi.');
    }

    public function sil(int $id)
    {
        [$uid, $salonId, $personelId] = $this->notSalonu($id);
        $this->ajanda->sil($id, $salonId, $personelId, $uid);

        return ApiCevap::basarili(null, 'Not silindi.');
    }

    /** @return array{0:int,1:int,2:?int} [yetkili id, salon id, personel id] */
    private function notSalonu(int $id): array
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu($this->ajanda->salonId($id), 'Not bulunamadı');
        $this->kapi->kapsamDenetle($uid, $salonId);

        return [$uid, $salonId, $this->portfoy->personelId($uid, $salonId)];
    }
}
