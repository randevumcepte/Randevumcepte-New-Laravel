<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Ayar\KaynakDurumIstegi;
use App\Services\Ayar\TakvimKaynakServisi;
use App\Services\Ayar\TakvimKaynakYonetimi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Ayarlar → Cihazlar / Odalar controller'larinin ORTAK eylemleri (liste, detay, form verisi,
 * durum, sira, sil). Eski panelde cihaz_* / oda_* olarak iki kopya olan uclar burada tek kez.
 * Kayit (ekle/duzenle) tur'e ozel FormRequest ile alt sinifta. Yetki: ayar.cihaz_oda_yonet.
 */
abstract class KaynakTabanController extends AyarTabanController
{
    abstract protected function yonetim(): TakvimKaynakYonetimi;

    protected function sekme(): string
    {
        return $this->yonetim()->tur()->sekme();
    }

    /** GET ?salon_id=&ara=&page=&per_page=&sirala=ad|durum_etiket&yon= (tumu=1 → sayfasiz). Varsayilan sira = takvim sirasi. */
    public function liste(Request $request)
    {
        $salonId = $this->okumaSalonu($request, $this->sekme());
        $liste = ListeIstegi::istekten($request, 25, ['ad', 'durum_etiket']);

        return ApiCevap::basarili($this->yonetim()->listele($salonId, $liste->dizi(), $request->boolean('tumu')));
    }

    /** GET /{id}?salon_id= */
    public function goster(Request $request, int $id)
    {
        return ApiCevap::basarili($this->yonetim()->detay($this->okumaSalonu($request, $this->sekme()), $id));
    }

    /** GET /form-verisi?salon_id= — form secenekleri / varsayilanlari */
    public function formVerisi(Request $request)
    {
        return ApiCevap::basarili($this->yonetim()->formVerisi($this->okumaSalonu($request, $this->sekme())));
    }

    /** PATCH /{id}/durum  body: {salon_id, musait, aciklama?} */
    public function durum(KaynakDurumIstegi $request, int $id)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, $this->sekme());

        return ApiCevap::basarili(app(TakvimKaynakServisi::class)->durum($this->yonetim()->tur(), $salonId, $yapanId, $id,
            $request->boolean('musait'), $request->input('aciklama')), 'Durum güncellendi');
    }

    /** PATCH /{id}/sira  body: {salon_id, delta: -1|1} — cevap: guncel tam liste */
    public function sira(Request $request, int $id)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, $this->sekme());
        $delta = (int) $request->input('delta') < 0 ? -1 : 1;
        app(TakvimKaynakServisi::class)->sirala($this->yonetim()->tur(), $salonId, $yapanId, $id, $delta);

        return ApiCevap::basarili($this->yonetim()->listele($salonId, ListeIstegi::istekten($request)->dizi(), true));
    }

    /** DELETE /{id}?salon_id= — pasife alir (eski "sil") */
    public function sil(Request $request, int $id)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, $this->sekme());
        $tur = $this->yonetim()->tur();
        app(TakvimKaynakServisi::class)->sil($tur, $salonId, $yapanId, $id);

        return ApiCevap::basarili(null, $tur->etiket().' başarıyla kaldırıldı');
    }
}
