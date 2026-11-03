<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Ayar\IsletmeGorselServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Ayarlar → Isletme Bilgileri: logo + galeri (yetki: ayar.salon_bilgi). Yukleme uclari
 * multipart/form-data (salon_id form alani). Dosya kurallari DosyaYukleme'de.
 */
class IsletmeGorselController extends AyarTabanController
{
    public function __construct(private IsletmeGorselServisi $gorsel) {}

    /** POST /api/v2/ayar/isletme/logo  multipart: salon_id, logo (v1 adi isletmelogo da olur) */
    public function logo(Request $request)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, 'isletme');
        $dosya = $request->file('logo') ?? $request->file('isletmelogo');

        return ApiCevap::basarili($this->gorsel->logoYukle($salonId, $yapanId, $dosya), 'Logo güncellendi');
    }

    /** GET /api/v2/ayar/isletme/gorseller?salon_id= */
    public function galeri(Request $request)
    {
        return ApiCevap::basarili($this->gorsel->galeri($this->okumaSalonu($request, 'isletme')));
    }

    /** POST /api/v2/ayar/isletme/gorseller  multipart: salon_id, gorseller[] */
    public function galeriEkle(Request $request)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, 'isletme');
        $dosyalar = array_values(array_filter((array) ($request->file('gorseller') ?? $request->file('isletmegorselleri') ?? [])));

        return ApiCevap::basarili($this->gorsel->galeriEkle($salonId, $yapanId, $dosyalar), 'Görseller eklendi');
    }

    /** DELETE /api/v2/ayar/isletme/gorseller/{id}?salon_id= */
    public function galeriSil(Request $request, int $id)
    {
        [$yapanId, $salonId] = $this->yazmaSalonu($request, 'isletme');

        return ApiCevap::basarili($this->gorsel->galeriSil($salonId, $yapanId, $id), 'Görsel silindi');
    }
}
