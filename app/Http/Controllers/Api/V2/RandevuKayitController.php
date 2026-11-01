<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Randevu\RandevuKayitServisi;
use App\Services\Randevu\RandevuOkumaServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\RandevuYazma;
use Illuminate\Http\Request;

/**
 * Randevu OLUSTUR / GUNCELLE (Faz 3b) — ince controller. Govde alanlari Flutter
 * randevu editoruyle ayni (bkz. RandevuKayitServisi).
 *   POST  /api/v2/randevu        → randevu.olustur      (salon_id govdede)
 *   PATCH /api/v2/randevu/{id}   → randevu.duzenle_iptal
 * Cakisma bir HATA degil, is sonucudur: basarili zarf + veri.cakismavar='1'.
 * 'dry_run' varsayilan true; gercek yazma RandevuYazma (config/randevu.php).
 */
class RandevuKayitController extends Controller
{
    private const ALANLAR = ['user_id', 'randevu_tarihi', 'randevu_saati', 'hizmetler', 'yardimcipersoneller',
        'tekrarlayan', 'tekrar_sayisi', 'tekrar_sikligi', 'notlar', 'durum', 'cakisanrandevuekle'];

    public function __construct(
        private RandevuKayitServisi $servis,
        private RandevuOkumaServisi $okuma,
        private GuvenlikKapisi $kapi,
    ) {}

    public function olustur(Request $request)
    {
        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        return $this->calistir($request, $salonId, 'randevu.olustur', $request->only(self::ALANLAR));
    }

    public function guncelle(Request $request, int $id)
    {
        $salonId = $this->okuma->salonIdGetir($id);
        if (! $salonId) {
            return ApiCevap::hata('Randevu bulunamadı', 404);
        }

        return $this->calistir($request, $salonId, 'randevu.duzenle_iptal', ['randevu_id' => $id] + $request->only(self::ALANLAR));
    }

    private function calistir(Request $request, int $salonId, string $yetki, array $girdi)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }
        $this->kapi->denetle($user->id, $salonId, [$yetki]);

        $yaz = ! $request->boolean('dry_run', true);
        if ($yaz && ! RandevuYazma::acik($salonId)) {
            return ApiCevap::hata('Randevu v2 yazma şu an kapalı (yalnızca dry_run)', 409);
        }

        $sonuc = $this->servis->kaydet($salonId, $girdi, (int) $user->id, $yaz);

        return ApiCevap::basarili($sonuc, $sonuc['cakismavar'] === '1' ? 'Çakışma var' : ($yaz ? 'Randevu kaydedildi' : 'DRY-RUN'));
    }
}
