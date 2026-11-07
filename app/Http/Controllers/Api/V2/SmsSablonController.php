<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Sms\SmsSablonServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * SMS şablonları. Her yazma güncel listeyi döner ({taslaklar[]}).
 *
 *   GET    /sms/sablonlar?salon_id=&yalniz_salon=
 *   POST   /sms/sablonlar          {baslik, icerik}
 *   PATCH  /sms/sablonlar/{id}     {baslik, icerik}
 *   DELETE /sms/sablonlar/{id}?salon_id=
 */
class SmsSablonController extends SmsTabanController
{
    public function __construct(private SmsSablonServisi $sablon) {}

    /** yalniz_salon=1: seçici listesi (yalnız kendi şablonları); salon üyesi okur, kişisel veri yoktur. */
    public function liste(Request $request)
    {
        if ($request->boolean('yalniz_salon')) {
            [, $salonId] = $this->salonDenetle($request, []);

            return ApiCevap::basarili(['taslaklar' => $this->sablon->salonunKendi($salonId)]);
        }

        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili(['taslaklar' => $this->sablon->liste($salonId)]);
    }

    public function ekle(Request $request)
    {
        [$uid, $salonId] = $this->salon($request);
        $v = $this->dogrula($request);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(['taslaklar' => $this->sablon->ekle($uid, $salonId, trim($v['baslik']), trim($v['icerik']))], 'Şablon başarıyla oluşturuldu', 201);
    }

    public function guncelle(Request $request, int $id)
    {
        [$uid, $salonId] = $this->salon($request);
        $v = $this->dogrula($request);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(['taslaklar' => $this->sablon->guncelle($uid, $salonId, $id, trim($v['baslik']), trim($v['icerik']))], 'Şablon güncellendi');
    }

    public function sil(Request $request, int $id)
    {
        [$uid, $salonId] = $this->salon($request);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(['taslaklar' => $this->sablon->sil($uid, $salonId, $id)], 'Şablon silindi');
    }

    /** @return array{baslik:string, icerik:string} */
    private function dogrula(Request $request): array
    {
        return $request->validate([
            'baslik' => ['required', 'string', 'max:190'],
            'icerik' => ['required', 'string', 'max:2000'],
        ], ['baslik.required' => 'Şablon adı boş olamaz.', 'icerik.required' => 'Mesaj içeriği boş olamaz.']);
    }
}
