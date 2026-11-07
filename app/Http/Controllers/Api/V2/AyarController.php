<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Ayar\CalismaSaatiIstegi;
use App\Http\Requests\Ayar\IsletmeBilgiIstegi;
use App\Http\Requests\Ayar\MusteriIndirimIstegi;
use App\Http\Requests\Ayar\TemaIstegi;
use App\Services\Ayar\AyarErisimServisi;
use App\Services\Ayar\CalismaSaatiServisi;
use App\Services\Ayar\IsletmeBilgiServisi;
use App\Services\Ayar\MusteriIndirimServisi;
use App\Services\Ayar\TemaServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Ayarlar API v2 (ince) — eski isletmeadmin/ayarlar sayfasi (web) ile Flutter Ayarlar
 * kartlarinin ORTAK ucu: ozet (sekmeler), isletme bilgileri, calisma saatleri,
 * musteri indirimleri, tema. Randevu/online saat RandevuAyarController'da, gorseller
 * IsletmeGorselController'da.
 */
class AyarController extends AyarTabanController
{
    public function __construct(
        private AyarErisimServisi $erisim,
        private IsletmeBilgiServisi $isletme,
        private CalismaSaatiServisi $saatler,
        private MusteriIndirimServisi $indirim,
        private TemaServisi $tema,
    ) {}

    /** GET /api/v2/ayar/ozet?salon_id= — yetkili olunan sekmeler (+ v2 / eski panel linki) */
    public function ozet(Request $request)
    {
        [$yetkiliId, $salonId] = $this->kapsamSalonu($request);

        return ApiCevap::basarili($this->erisim->ozet($yetkiliId, $salonId));
    }

    /** GET /api/v2/ayar/isletme?salon_id= — bilgiler + logo + galeri + SEO + secenekler */
    public function isletme(Request $request)
    {
        return ApiCevap::basarili($this->isletme->getir($this->okumaSalonu($request, 'isletme')));
    }

    /** PATCH /api/v2/ayar/isletme — kismi (gonderilen alanlar) */
    public function isletmeKaydet(IsletmeBilgiIstegi $request)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, 'isletme');

        return ApiCevap::basarili($this->isletme->kaydet($salonId, $yapanId, $request->veri()), 'İşletme bilgileri başarıyla kaydedildi');
    }

    /** GET /api/v2/ayar/calisma-saatleri?salon_id= — 7 gun calisma + mola */
    public function calismaSaatleri(Request $request)
    {
        return ApiCevap::basarili($this->saatler->getir($this->okumaSalonu($request, 'calisma_saatleri')));
    }

    /** PUT /api/v2/ayar/calisma-saatleri  body: {salon_id, gunler[7], yalniz?, gece_devri?} (son ikisi mobil) */
    public function calismaSaatleriKaydet(CalismaSaatiIstegi $request)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, 'calisma_saatleri');

        return ApiCevap::basarili($this->saatler->kaydet($salonId, $yapanId, $request->validated('gunler'), $request->validated('yalniz'), $request->boolean('gece_devri')), 'Çalışma saatleri başarıyla kaydedildi');
    }

    /** GET /api/v2/ayar/musteri-indirimleri?salon_id= */
    public function musteriIndirimleri(Request $request)
    {
        return ApiCevap::basarili($this->indirim->getir($this->okumaSalonu($request, 'musteri_indirimleri')));
    }

    /** PUT /api/v2/ayar/musteri-indirimleri  body: {salon_id, sadik:{acik,yuzde}, aktif:{acik,yuzde}} */
    public function musteriIndirimKaydet(MusteriIndirimIstegi $request)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, 'musteri_indirimleri');

        return ApiCevap::basarili($this->indirim->kaydet($salonId, $yapanId, $request->veri()), 'İşlem başarıyla kaydedildi');
    }

    /** GET /api/v2/ayar/tema?salon_id= — salonun her uyesi okur (Flutter acilista uygular) */
    public function tema(Request $request)
    {
        [$yetkiliId, $salonId] = $this->kapsamSalonu($request);

        return ApiCevap::basarili($this->tema->getir($salonId) + [
            'duzenleyebilir' => $this->erisim->temaYazabilir($yetkiliId, $salonId),
        ]);
    }

    /** PUT /api/v2/ayar/tema  body: {salon_id, preset, customSeedArgb?, brightnessMode, personel_rolu?} — rol 1-3 */
    public function temaKaydet(TemaIstegi $request)
    {
        [$yapanId, $salonId] = $this->kapsamSalonu($request);
        $this->erisim->temaYazmaDenetle($yapanId, $salonId, $request->boolean('personel_rolu'));
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->tema->kaydet($salonId, $yapanId, $request->veri()), 'Tema kaydedildi');
    }
}
