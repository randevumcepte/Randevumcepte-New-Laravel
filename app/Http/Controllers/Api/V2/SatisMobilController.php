<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Satis\AdisyonIslemServisi;
use App\Services\Satis\MobilKalemServisi;
use App\Services\Satis\MobilSatisOkumaServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Mobil satis uclari — eski ApiController cevap ve yazim kuraliyla. Kimlik + salon kapsami zorunlu;
 * eski mobil rol ayirmadigi icin ek yetki aranmaz. Okuma ve yazma `mobil` bayragi ister: bayrak yalniz imzali
 * mobil token'la istege ulasir (MobilBayraklar), web token'i bu uclari cagiramaz.
 */
class SatisMobilController extends V2Controller
{
    protected ?string $yazmaAlani = 'tahsilat';

    protected string $yazmaKapaliMesaj = 'Düzenleme henüz etkin değil';

    public function __construct(
        private MobilKalemServisi $kalem,
        private MobilSatisOkumaServisi $okuma,
        private AdisyonIslemServisi $islem,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
    ) {}

    public function hizmetKaydet(Request $request)
    {
        return ApiCevap::basarili($this->kalem->hizmetKaydet($this->yazmaSalonu($request), $request->all(), (int) $this->kullanici()->id));
    }

    public function urunKaydet(Request $request)
    {
        return ApiCevap::basarili($this->kalem->urunKaydet($this->yazmaSalonu($request), $request->all(), (int) $this->kullanici()->id));
    }

    public function paketKaydet(Request $request)
    {
        return ApiCevap::basarili($this->kalem->paketKaydet($this->yazmaSalonu($request), $request->all(), (int) $this->kullanici()->id));
    }

    public function paketGunluk(Request $request)
    {
        return ApiCevap::basarili($this->okuma->gunlukPaketler($this->okumaSalonu($request), $request->input('musteridanisan')));
    }

    public function urunGunluk(Request $request)
    {
        return ApiCevap::basarili($this->okuma->gunlukUrunler($this->okumaSalonu($request), $request->input('musteridanisan')));
    }

    public function faturasizDurum(Request $request)
    {
        return ApiCevap::basarili(['faturasiz_gizle' => $this->islem->faturasizGizleAktif($this->okumaSalonu($request)) ? 1 : 0]);
    }

    public function musteriTuru(Request $request)
    {
        return ApiCevap::basarili(['tur' => $this->okuma->musteriTuru($request->integer('musteri_id'), $this->okumaSalonu($request))]);
    }

    public function musteriKarti(Request $request, int $id)
    {
        $salonId = $this->okumaSalonu($request);
        $telefonGor = $this->yetki->yetkiliYetkiVar($this->kullanici()->id, $salonId, 'musteri.telefon_gor');

        return ApiCevap::basarili($this->okuma->musteriKarti($id, $salonId, $telefonGor));
    }

    private function okumaSalonu(Request $request): int
    {
        $this->mobilOlmali($request);
        $salonId = $request->integer('salon_id');
        $this->kapi->kapsamDenetle($this->kullanici()->id, $salonId);

        return $salonId;
    }

    private function yazmaSalonu(Request $request): int
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();
        $this->mobilOlmali($request);
        $salonId = $request->integer('sube');
        $this->kapi->kapsamDenetle($user->id, $salonId);

        return $salonId;
    }
}
