<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Personel\PersonelOkumaServisi;
use App\Services\Personel\YetkiYonetimServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\KapsamServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Personel YETKI YONETIMI API v2 controller (ince).
 *
 * Faz 1 — SALT OKUMA: sema (referans), benim (kendi yetkilerim), getir (hedef personel).
 * kaydet (yazma) Faz 2b'de — kill-switch + force_logout koprusu ile.
 */
class PersonelYetkiController extends Controller
{
    public function __construct(
        private YetkiYonetimServisi $servis,
        private PersonelOkumaServisi $okuma,
        private GuvenlikKapisi $kapi,
        private KapsamServisi $kapsam,
    ) {}

    /** GET /api/v2/personel-yetki/sema — yetki semasi (public referans; yalnizca kimlik gerekir). */
    public function sema()
    {
        $this->kullanici();

        return ApiCevap::basarili($this->servis->sema());
    }

    /** GET /api/v2/personel-yetki/benim?salon_id= — giris yapanin kendi efektif yetkileri. */
    public function benimYetkilerim(Request $request)
    {
        $user = $this->kullanici();
        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        // Kapsam: kullanici bu salona bagli degilse kendi yetkisini de goremez (sizinti onleme).
        if (! $this->kapsam->salonaBagliMi($user->id, $salonId)) {
            throw new YetkisizErisimException('Bu işletmeye erişim yetkiniz yok');
        }

        return ApiCevap::basarili($this->servis->benimYetkilerim($user->id, $salonId));
    }

    /** GET /api/v2/personel/{id}/yetki — hedef personelin yetki ayarlari  (personel.yetki_yonet) */
    public function getir(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->salonVeyaBulunamadi($id);

        $this->kapi->denetle($user->id, $salonId, ['personel.yetki_yonet']);

        return ApiCevap::basarili($this->servis->getir($id, $salonId));
    }

    /**
     * PATCH /api/v2/personel/{id}/yetki  body: {sablon, ayarlar}  (personel.yetki_yonet)
     * Faz 2b — kill-switch arkasinda. NOT: eski sistemdeki YETKI_DEGISTI force_logout
     * push'u bu projede HENUZ YOK; kapru kurulana kadar canli token gecersizlenmez.
     */
    public function kaydet(Request $request, int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->salonVeyaBulunamadi($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.yetki_yonet']);

        if (! config('personel.yazma_aktif')) {
            return ApiCevap::hata('Personel yazma henüz etkin değil (PERSONEL_V2_YAZMA_AKTIF).', 409);
        }

        $ayarlar = $request->input('ayarlar');
        if (is_string($ayarlar)) {
            $decoded = json_decode($ayarlar, true);
            $ayarlar = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($ayarlar)) {
            $ayarlar = [];
        }

        $this->servis->kaydet($id, $salonId, (string) $request->input('sablon', 'ozel'), $ayarlar);

        return ApiCevap::basarili(null, 'Yetkiler kaydedildi.');
    }

    // ---------------------------------------------------------------------

    private function kullanici()
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        return $user;
    }

    private function salonVeyaBulunamadi(int $id): int
    {
        $salonId = $this->okuma->salonIdGetir($id);
        if (! $salonId) {
            abort(404, 'Personel bulunamadı');
        }

        return $salonId;
    }
}
