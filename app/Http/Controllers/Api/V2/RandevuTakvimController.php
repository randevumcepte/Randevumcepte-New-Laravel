<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Randevu\RandevuOkumaServisi;
use App\Services\Randevu\RandevuTakvimYazServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Takvim uzerinden yazma uclari (Faz 3a) — ince controller.
 *   saat kapama ekle/sil → randevu.kapanis_blok_ekle
 *   surukle-birak/boyut  → randevu.duzenle_iptal
 * Hepsi 'dry_run' alir (varsayilan true); gercek yazma config('randevu.yazma_aktif').
 */
class RandevuTakvimController extends Controller
{
    public function __construct(
        private RandevuTakvimYazServisi $servis,
        private RandevuOkumaServisi $okuma,
        private GuvenlikKapisi $kapi,
    ) {}

    /** POST /api/v2/randevu/saat-kapama  (salon_id, tarih, saat?, saat_bitis?, personel_id|oda_id|cihaz_id, not?, tekrar_sayisi?, tekrar_sikligi?) */
    public function saatKapamaEkle(Request $request)
    {
        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        return $this->calistir($request, $salonId, 'randevu.kapanis_blok_ekle', fn ($uid, $yaz) => $this->servis->saatKapamaEkle(
            $salonId,
            $request->only(['tarih', 'saat', 'saat_bitis', 'personel_id', 'oda_id', 'cihaz_id', 'not', 'tekrar_sayisi', 'tekrar_sikligi']),
            $uid,
            $yaz,
        ));
    }

    /** DELETE /api/v2/randevu/saat-kapama/{id} */
    public function saatKapamaSil(Request $request, int $id)
    {
        $salonId = $this->okuma->salonIdGetir($id);
        if (! $salonId) {
            return ApiCevap::hata('Kayıt bulunamadı', 404);
        }

        return $this->calistir($request, $salonId, 'randevu.kapanis_blok_ekle', fn ($uid, $yaz) => $this->servis->saatKapamaSil($id, $uid, $yaz));
    }

    /** PATCH /api/v2/randevu/kalem/{rh}/tasi  (baslangic, bitis, takvim_turu, resource_id) */
    public function tasi(Request $request, int $rh)
    {
        $salonId = $this->servis->kalemSalonId($rh);
        if (! $salonId) {
            return ApiCevap::hata('Randevu kaydı bulunamadı', 404);
        }

        return $this->calistir($request, $salonId, 'randevu.duzenle_iptal', fn ($uid, $yaz) => $this->servis->tasi(
            $rh,
            $request->only(['baslangic', 'bitis', 'takvim_turu', 'resource_id']),
            $uid,
            $yaz,
        ));
    }

    /** Ortak iskelet: kimlik → kapsam/yetki → dry-run/kill-switch → servis → zarf. */
    private function calistir(Request $request, int $salonId, string $yetki, callable $islem)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }
        $this->kapi->denetle($user->id, $salonId, [$yetki]);

        $yaz = ! $request->boolean('dry_run', true);
        if ($yaz && ! config('randevu.yazma_aktif')) {
            return ApiCevap::hata('Randevu v2 yazma şu an kapalı (yalnızca dry_run)', 409);
        }

        $sonuc = $islem((int) $user->id, $yaz);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj'] ?? null);
    }
}
