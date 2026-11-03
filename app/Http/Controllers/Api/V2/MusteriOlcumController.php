<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\BulunamadiException;
use App\Http\Requests\Musteri\OlcumIstegi;
use App\Services\Musteri\OlcumServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Vucut olcumu (studyo modu) — yetkiler eski StoreAdminController::musteri_olcum_* ile ayni:
 *   GET    /musteri/{id}/olcumler?salon_id=     randevu.takvim_gor
 *   POST   /musteri/{id}/olcumler {salon_id}    randevu.takvim_gor   (kill-switch)
 *   DELETE /musteri/olcum/{olcumId}             randevu.duzenle_iptal (kill-switch; salon olcumden)
 */
class MusteriOlcumController extends MusteriTabanController
{
    public function __construct(private OlcumServisi $olcum) {}

    public function liste(Request $request, int $id)
    {
        [, $salonId] = $this->musteriDenetle($request, $id, 'randevu.takvim_gor');

        return ApiCevap::basarili($this->olcum->liste($salonId, $id));
    }

    public function ekle(OlcumIstegi $request, int $id)
    {
        [$uid, $salonId] = $this->musteriDenetle($request, $id, 'randevu.takvim_gor');
        $this->yazmaAcikMi();

        return ApiCevap::basarili(
            $this->olcum->ekle($salonId, $id, $request->validated(), $this->portfoy()->personelId($uid, $salonId)),
            'Ölçüm kaydedildi.', 201,
        );
    }

    public function sil(Request $request, int $olcumId)
    {
        $sahip = $this->olcum->sahibi($olcumId) ?? throw new BulunamadiException('Ölçüm bulunamadı');
        $request->merge(['salon_id' => (int) $sahip->salon_id]);
        $this->musteriDenetle($request, (int) $sahip->user_id, 'randevu.duzenle_iptal');
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->olcum->sil((int) $sahip->salon_id, $olcumId), 'Ölçüm silindi.');
    }
}
