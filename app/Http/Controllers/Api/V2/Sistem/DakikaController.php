<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Entegrasyon\DakikaYonetimi;
use App\Domain\SistemYonetim\SistemYetki;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 *   GET /sistem/dakika + ListeIstegi (sirala: salon|sayim|tanimli|kullanilan|kalan|yuzde) → data, ozet, izinler. Destek yalniz kendi salonlari.
 *   PUT /sistem/dakika/{salonId} {tanimli_dakika, sayim_baslangic?} — super_admin,yonetici (rota)
 */
class DakikaController extends SistemTabanController
{
    protected const ETIKETLER = ['tanimli_dakika' => 'Tanımlı dakika', 'sayim_baslangic' => 'Sayım başlangıç'];

    public function __construct(private DakikaYonetimi $servis) {}

    public function liste(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 25, array_keys(DakikaYonetimi::SIRALAMA), 'yuzde', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->liste($liste, $this->yonetici()) + ['izinler' => ['yaz' => $this->izinli(SistemYetki::YONETIM)]]);
    }

    public function kaydet(Request $request, int $salonId)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, [
            'tanimli_dakika'  => ['required', 'integer', 'min:0'],
            'sayim_baslangic' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $salon = $this->salon($salonId, ['id', 'salon_adi']);
        $this->servis->kaydet($salon, (int) $g['tanimli_dakika'], $g['sayim_baslangic'] ?? null, $this->yonetici());

        return ApiCevap::basarili(null, $salon->salon_adi.' için tanımlı dakika güncellendi: '.(int) $g['tanimli_dakika'].' dk');
    }
}
