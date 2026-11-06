<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Domain\SistemYonetim\SistemYetki;
use App\Domain\SistemYonetim\DuyuruHedefTipi;
use App\Domain\SistemYonetim\DuyuruTipi;
use App\Services\SistemYonetim\Icerik\DuyuruServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 *   GET    /sistem/duyuru/secenekler      → {tipler, hedef_tipleri, iller, yazabilir}
 *   GET    /sistem/duyuru + ListeIstegi (sirala: tarih|baslik|tip|hedef|baslangic|durum|olusturan)
 *   GET    /sistem/duyuru/{id}            → {duyuru, hedef_sayisi, okundu_sayisi, okundu_orani}
 *   GET    /sistem/duyuru/{id}/okuyanlar + ListeIstegi (sirala: tarih|salon|yetkili)
 *   GET    /sistem/duyuru/{id}/form       → duzenleme formu degerleri
 *   POST   /sistem/duyuru, PUT|DELETE /{id}
 */
class DuyuruController extends DestekTabanController
{
    public function __construct(private DuyuruServisi $servis) {}

    public function secenekler()
    {
        return ApiCevap::basarili($this->servis->secenekler() + ['yazabilir' => $this->izinli(SistemYetki::YONETIM)]);
    }

    public function liste(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 25, array_keys(DuyuruServisi::SIRALAMA), 'tarih', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->listele($liste));
    }

    public function detay(int $id)
    {
        return ApiCevap::basarili($this->servis->detay($id));
    }

    public function okuyanlar(Request $request, int $id)
    {
        $liste = ListeIstegi::istekten($request, 25, array_keys(DuyuruServisi::OKUYAN_SIRALAMA), 'tarih', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->okuyanlar($id, $liste));
    }

    public function form(int $id)
    {
        return ApiCevap::basarili($this->servis->formVerisi($id));
    }

    public function olustur(Request $request)
    {
        $this->yazmaAcikMi();

        return ApiCevap::basarili(['id' => $this->servis->olustur($this->girdi($request), $this->yonetici())], 'Duyuru oluşturuldu.', 201);
    }

    public function guncelle(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->guncelle($id, $this->girdi($request));

        return ApiCevap::basarili(null, 'Duyuru güncellendi.');
    }

    public function sil(int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->sil($id);

        return ApiCevap::basarili(null, 'Duyuru silindi.');
    }

    /** cta_link yalniz panel yolu ya da http(s): javascript: gibi semalar butona yazilamaz. */
    private function girdi(Request $request): array
    {
        return $this->dogrula($request, [
            'baslik'           => ['required', 'string', 'min:2', 'max:200'],
            'icerik'           => ['required', 'string', 'min:2', 'max:60000'],
            'tip'              => ['required', Rule::enum(DuyuruTipi::class)],
            'hedef_tipi'       => ['required', Rule::enum(DuyuruHedefTipi::class)],
            'salon_ids'        => ['nullable', 'array'],
            'salon_ids.*'      => ['integer', 'min:1'],
            'il_ids'           => ['nullable', 'array'],
            'il_ids.*'         => ['integer', 'min:1'],
            'baslangic_tarihi' => ['nullable', 'date_format:Y-m-d H:i'],
            'bitis_tarihi'     => ['nullable', 'date_format:Y-m-d H:i'],
            'aktif'            => ['nullable', 'boolean'],
            'sticky'           => ['nullable', 'boolean'],
            'cta_metin'        => ['nullable', 'string', 'max:80'],
            'cta_link'         => ['nullable', 'string', 'max:250', 'regex:#^(/(?!/)|https?://)#i'],
        ]);
    }
}
