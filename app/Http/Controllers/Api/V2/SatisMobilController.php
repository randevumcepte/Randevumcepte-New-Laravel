<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Musteri\MusteriOkumaServisi;
use App\Services\Satis\AdisyonIslemServisi;
use App\Services\Satis\MobilKalemServisi;
use App\Services\Satis\MobilSatisOkumaServisi;
use App\Services\Satis\SatisListeKapsami;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use App\Support\Yetki\IslemYetkileri;
use Illuminate\Http\Request;

/**
 * Mobil satis uclari — eski ApiController cevap ve yazim kuraliyla. Okuma ve yazma `mobil` bayragi ister: bayrak yalniz
 * imzali mobil token'la istege ulasir (MobilBayraklar), web token'i bu uclari cagiramaz.
 *
 * Yetki web ile AYNI kural (2026-11-12): kalem yazmalari web kalem uclari gibi satis.adisyon_olustur; gunluk satis
 * listeleri ve satis ekrani okumalari web satis listesi gibi SatisListeKapsami (SATIS_GOR + personel kisiti); musteri
 * karti web musteri detayi gibi (MusteriTabanController::DETAY + portfoy). Sira: bayrak, kimlik, kapsam, yetki, kill-switch.
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
        private SatisListeKapsami $listeKapsami,
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
        [$salonId, $kisit] = $this->listeSalonu($request);

        return ApiCevap::basarili($this->okuma->gunlukPaketler($salonId, $request->input('musteridanisan'), $kisit));
    }

    public function urunGunluk(Request $request)
    {
        [$salonId, $kisit] = $this->listeSalonu($request);

        return ApiCevap::basarili($this->okuma->gunlukUrunler($salonId, $request->input('musteridanisan'), $kisit));
    }

    public function faturasizDurum(Request $request)
    {
        return ApiCevap::basarili(['faturasiz_gizle' => $this->islem->faturasizGizleAktif($this->okumaSalonu($request, IslemYetkileri::SATIS_GOR)) ? 1 : 0]);
    }

    public function musteriTuru(Request $request)
    {
        return ApiCevap::basarili(['tur' => $this->okuma->musteriTuru($request->integer('musteri_id'), $this->okumaSalonu($request, IslemYetkileri::SATIS_GOR))]);
    }

    /** Tahsilat ekrani musteri karti (saglik alanlari dahil): web musteri detayi ile ayni yetki + portfoy kapsami. */
    public function musteriKarti(Request $request, MusteriOkumaServisi $musteri, int $id)
    {
        $salonId = $this->okumaSalonu($request, MusteriTabanController::DETAY);
        $uid = (int) $this->kullanici()->id;
        $musteri->kapsamdaZorunlu($uid, $salonId, $id);
        $telefonGor = $this->yetki->yetkiliYetkiVar($uid, $salonId, 'musteri.telefon_gor');

        return ApiCevap::basarili($this->okuma->musteriKarti($id, $salonId, $telefonGor));
    }

    /** @param  array<string>  $yetki */
    private function okumaSalonu(Request $request, array $yetki): int
    {
        $this->mobilOlmali($request);
        $salonId = $request->integer('salon_id');
        $this->kapi->denetle($this->kullanici()->id, $salonId, $yetki);

        return $salonId;
    }

    /** @return array{0:int,1:?int} [salon id, personel kisiti] — web satis listesiyle ayni kapi ve kisit. */
    private function listeSalonu(Request $request): array
    {
        $this->mobilOlmali($request);
        $salonId = $request->integer('salon_id');

        return [$salonId, $this->listeKapsami->denetle((int) $this->kullanici()->id, $salonId, false)['kisit']];
    }

    private function yazmaSalonu(Request $request): int
    {
        $user = $this->kullanici();
        $this->mobilOlmali($request);
        $salonId = $request->integer('sube');
        $this->kapi->denetle($user->id, $salonId, IslemYetkileri::ADISYON_DUZENLE);
        $this->yazmaAcikMi();

        return $salonId;
    }
}
