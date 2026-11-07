<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Ayar\AyarSozluk;
use App\Http\Requests\Ayar\HavuzHizmetIstegi;
use App\Http\Requests\Ayar\HizmetIstegi;
use App\Http\Requests\Ayar\HizmetKategoriIstegi;
use App\Services\Ayar\HizmetOkumaServisi;
use App\Services\Ayar\HizmetYazServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Ayarlar → Hizmetler (ince). Okuma: sekme yetkisi (hizmet.tanim_olustur VEYA hizmet.kategori_yonet);
 * hizmet yazma hizmet.tanim_olustur, kategori ekleme hizmet.kategori_yonet (eskiyle ayni ayrim).
 */
class HizmetController extends AyarTabanController
{
    private const TANIM = 'hizmet.tanim_olustur';
    private const KATEGORI = 'hizmet.kategori_yonet';

    public function __construct(
        private HizmetOkumaServisi $okuma,
        private HizmetYazServisi $yaz,
    ) {}

    /** GET /api/v2/ayar/hizmetler?salon_id=&kategori_id=&ara=&page=&per_page=&sirala=&yon= (tumu=1 → sayfasiz) + ozet */
    public function liste(Request $request)
    {
        $salonId = $this->okumaSalonu($request, 'hizmetler');
        $liste = ListeIstegi::istekten($request, 25, ['hizmet_adi', 'hizmet_kategorisi', 'sure_dk', 'fiyat', 'maliyet']);

        return ApiCevap::basarili($this->okuma->listele($salonId, $liste->dizi(), $request->integer('kategori_id') ?: null, $request->boolean('tumu')));
    }

    /** GET /api/v2/ayar/hizmetler/{id}?salon_id= (id = salon hizmet kaydi) */
    public function goster(Request $request, int $id)
    {
        return ApiCevap::basarili($this->okuma->detay($this->okumaSalonu($request, 'hizmetler'), $id));
    }

    /** GET /api/v2/ayar/hizmetler/form-verisi?salon_id= — kategoriler, personeller, cihazlar, cinsiyetler, havuz */
    public function formVerisi(Request $request)
    {
        return ApiCevap::basarili($this->okuma->formVerisi($this->okumaSalonu($request, 'hizmetler')));
    }

    /** POST /api/v2/ayar/hizmetler — salona ozel yeni hizmet */
    public function ekle(HizmetIstegi $request)
    {
        [$yapanId, $salonId] = $this->yetkiliYazma($request, self::TANIM);

        return ApiCevap::basarili($this->yaz->yeniEkle($salonId, $yapanId, $request->veri()), 'Hizmet başarıyla eklendi');
    }

    /**
     * POST /api/v2/ayar/hizmetler/havuzdan  body: {salon_id, sekme_yetkisi?, hizmetler[]}
     * sekme_yetkisi (mobil): mobil menu yalniz kategori_yonet ile de aciliyor, kayit ayni kuralla gecer.
     */
    public function havuzdanEkle(HavuzHizmetIstegi $request)
    {
        $yetki = $request->boolean('sekme_yetkisi') ? AyarSozluk::yetki('hizmetler') : self::TANIM;
        [$yapanId, $salonId] = $this->yetkiliYazma($request, $yetki);

        return ApiCevap::basarili($this->yaz->havuzdanEkle($salonId, $yapanId, $request->validated('hizmetler')), 'Hizmet(-ler) başarıyla eklendi');
    }

    /** PATCH /api/v2/ayar/hizmetler/{id} — kismi */
    public function guncelle(HizmetIstegi $request, int $id)
    {
        [$yapanId, $salonId] = $this->yetkiliYazma($request, self::TANIM);

        return ApiCevap::basarili($this->yaz->guncelle($salonId, $yapanId, $id, $request->veri()), 'Hizmet başarıyla güncellendi');
    }

    /** DELETE /api/v2/ayar/hizmetler/{id}?salon_id= — pasife alir */
    public function kaldir(Request $request, int $id)
    {
        [$yapanId, $salonId] = $this->yetkiliYazma($request, self::TANIM);
        $this->yaz->kaldir($salonId, $yapanId, $id);

        return ApiCevap::basarili(null, 'Hizmet başarıyla kaldırıldı');
    }

    /** POST /api/v2/ayar/hizmet-kategorileri  body: {salon_id, kategori_adi} */
    public function kategoriEkle(HizmetKategoriIstegi $request)
    {
        [$yapanId, $salonId] = $this->yetkiliYazma($request, self::KATEGORI);

        return ApiCevap::basarili($this->yaz->kategoriEkle($salonId, $yapanId, (string) $request->validated('kategori_adi')), 'Kategori kaydedildi');
    }
}
