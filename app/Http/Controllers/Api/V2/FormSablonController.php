<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Form\FormSablonServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Form ŞABLONLARI + varsayılan sözleşme metni API v2 controller (ince).
 * Eski /isletmeyonetim/form-sablonlari* ve v1 form-sablonlari-* / sozlesme-varsayilan-*
 * karşılığı. Eskide yazma uçlarında yetki denetimi YOKTU; burada:
 *   - liste/getir: form.olustur VEYA form.gonder
 *   - ekle/düzenle/sil/sırala: form.olustur
 *   - varsayılan sözleşme metni kaydet: form.gonder (eskiyle aynı)
 * Yazmalar config('form.yazma_aktif') kill-switch arkasında.
 */
class FormSablonController extends V2Controller
{
    protected ?string $yazmaAlani = 'form';

    public function __construct(
        private FormSablonServisi $servis,
    ) {}

    /** GET /api/v2/form/sablon?salon_id= */
    public function liste(Request $request)
    {
        [, $salonId] = $this->salonDenetle($request, ['form.olustur', 'form.gonder']);

        return ApiCevap::basarili($this->servis->liste($salonId));
    }

    /** GET /api/v2/form/sablon/{id}?salon_id= */
    public function goster(Request $request, int $id)
    {
        [, $salonId] = $this->salonDenetle($request, ['form.olustur', 'form.gonder']);

        return ApiCevap::basarili($this->servis->getir($salonId, $id));
    }

    /** POST /api/v2/form/sablon  |  PATCH /api/v2/form/sablon/{id}  body: {salon_id, form_adi, aciklama, is_sozlesme, sorular[]} */
    public function kaydet(Request $request, ?int $id = null)
    {
        [$uid, $salonId] = $this->salonDenetle($request, ['form.olustur']);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->kaydet($salonId, $id, [
            'form_adi'    => $request->input('form_adi'),
            'aciklama'    => $request->input('aciklama'),
            'is_sozlesme' => $request->boolean('is_sozlesme'),
            // Flutter (v1 uyumu) sorular_json string gönderebilir; web dizi gönderir.
            'sorular'     => $request->input('sorular', $request->input('sorular_json')),
        ], $uid), $id !== null ? 'Form şablonu güncellendi.' : 'Form şablonu oluşturuldu.');
    }

    /** DELETE /api/v2/form/sablon/{id}?salon_id= */
    public function sil(Request $request, int $id)
    {
        [$uid, $salonId] = $this->salonDenetle($request, ['form.olustur']);
        $this->yazmaAcikMi();
        $this->servis->sil($salonId, $id, $uid);

        return ApiCevap::basarili(null, 'Silindi');
    }

    /** PATCH /api/v2/form/sablon/{id}/sira  body: {salon_id, yon: yukari|asagi} — güncel listeyi döner */
    public function sirala(Request $request, int $id)
    {
        [$uid, $salonId] = $this->salonDenetle($request, ['form.olustur']);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->sirala($salonId, $id, (string) $request->input('yon'), $uid));
    }

    /** GET /api/v2/form/sozlesme-metni?salon_id= */
    public function sozlesmeMetni(Request $request)
    {
        [, $salonId] = $this->salonDenetle($request, ['form.olustur', 'form.gonder']);

        return ApiCevap::basarili($this->servis->sozlesmeMetni($salonId));
    }

    /** PUT /api/v2/form/sozlesme-metni  body: {salon_id, sozlesme_metni} (boş = fabrika metnine dön) */
    public function sozlesmeMetniKaydet(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, ['form.gonder']);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(
            $this->servis->sozlesmeMetniKaydet($salonId, $request->input('sozlesme_metni'), $uid),
            'Bu metin varsayılan sözleşme şartlarınız olarak kaydedildi. Bundan sonra otomatik dolu gelecek.'
        );
    }
}
