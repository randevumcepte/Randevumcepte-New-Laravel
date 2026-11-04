<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Ders\OturumIstegi;
use App\Services\Ders\DanisanDersServisi;
use App\Services\Ders\DersOturumServisi;
use App\Services\Randevu\RandevuFormServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Ders oturumu (eski /ders-oturum-*, /ders-gun-liste, /ders-musteri-ara).
 *
 *   GET    /ders/oturum?salon_id=&tarih=|tarih1=&tarih2=&personel_id=   (rol-5 kendi dersleri)
 *   GET    /ders/oturum/{id}          oturum + katilimcilar (telefon yetkiye gore maskeli) + durum sozlugu
 *   POST   /ders/oturum {salon_id, tarih, saat, saat_bitis, kapasite, ders_tipi?, personel_id?, hizmet_id?}
 *   PATCH  /ders/oturum/{id}
 *   DELETE /ders/oturum/{id}          soft iptal + hak iadesi + bildirim
 *   GET    /ders/musteri-ara?salon_id=&q=
 *   GET    /ders/musteri/{id}/dersler?salon_id=
 */
class DersOturumController extends DersTabanController
{
    public function __construct(private DersOturumServisi $servis) {}

    public function liste(Request $request)
    {
        [$uid, $salonId] = $this->dersSalonu($request, self::OKU);
        $tarih = $request->input('tarih', date('Y-m-d'));
        $kisit = $this->servis->kisitliPersonel($salonId, $uid, $this->yetkiVar($uid, $salonId, 'randevu.tum_personel_gor'));

        return ApiCevap::basarili($this->servis->liste(
            $salonId,
            $request->input('tarih1', $tarih),
            $request->input('tarih2', $request->input('tarih1', $tarih)),
            $kisit ?? ($request->integer('personel_id') ?: null),
        ));
    }

    public function goster(int $id)
    {
        [$uid, $salonId] = $this->oturumSalonu($id, self::OKU);

        return ApiCevap::basarili($this->servis->detay($id, $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor')));
    }

    public function ekle(OturumIstegi $request)
    {
        [$uid, $salonId] = $this->dersSalonu($request, self::YAZ);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->kaydet($salonId, null, $request->validated(), $this->personelId($uid, $salonId), $uid), 'Ders oluşturuldu.', 201);
    }

    public function guncelle(OturumIstegi $request, int $id)
    {
        [$uid, $salonId] = $this->oturumSalonu($id, self::YAZ);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->kaydet($salonId, $id, $request->validated(), null, $uid), 'Ders güncellendi.');
    }

    public function iptal(int $id)
    {
        [$uid] = $this->oturumSalonu($id, self::SIL);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->iptal($id, $uid), 'Ders iptal edildi.');
    }

    public function musteriAra(Request $request, RandevuFormServisi $form)
    {
        [$uid, $salonId] = $this->dersSalonu($request, self::OKU);

        return ApiCevap::basarili($form->musteriAra($salonId, (string) $request->input('q', ''), $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor')));
    }

    public function musteriDersleri(Request $request, int $id, DanisanDersServisi $danisan)
    {
        [, $salonId] = $this->dersSalonu($request, self::OKU);

        return ApiCevap::basarili($danisan->dersler($id, $salonId));
    }

    /** @return array{0:int,1:int} */
    private function oturumSalonu(int $id, string $yetki): array
    {
        return $this->kayitSalonu($this->servis->salonId($id), $yetki, 'Ders bulunamadı');
    }
}
