<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Randevu\RandevuOkumaServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Randevu API v2 controller (ince). Is mantigi RandevuOkumaServisi'nde; burada
 * yalnizca kimlik/kapsam/yetki denetimi + servis cagrisi + standart zarf.
 *
 * Faz 1 — SALT OKUMA: takvim yuku + tek randevu detayi. Yazma yollari
 * (ekle/guncelle/geldi/gelmedi/iptal/telafi) sonraki fazlarda.
 */
class RandevuController extends Controller
{
    public function __construct(
        private RandevuOkumaServisi $servis,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
    ) {}

    /**
     * GET /api/v2/randevu/takvim  (auth:isletmeyonetim-api)
     * Sorgu: salon_id (zorunlu), tarih1, tarih2, personel_id, takvim_turu.
     * Eski /randevular/{salon}/0 ucunun yapisal karsiligi.
     */
    public function takvim(Request $request)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        // Kapsam + yetki: takvimi gorme (OR olustur/duzenle de gorebilir — eski gate ile ayni).
        $this->kapi->denetle($user->id, $salonId, [
            'randevu.takvim_gor',
            'randevu.olustur',
            'randevu.duzenle_iptal',
        ]);

        $personelId = $request->filled('personel_id') ? (int) $request->input('personel_id') : null;

        $veri = $this->servis->takvim(
            $salonId,
            $request->input('tarih1'),
            $request->input('tarih2'),
            $personelId,
            $request->input('takvim_turu'),
            (int) $user->id,
        );

        return ApiCevap::basarili($veri);
    }

    /**
     * GET /api/v2/randevu/{id}  (auth:isletmeyonetim-api)
     * Tek randevu detayi. Telefon musteri.telefon_gor yoksa maskelenir.
     */
    public function goster(int $id)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        $salonId = $this->servis->salonIdGetir($id);
        if (! $salonId) {
            // Kapsam sizintisini onlemek icin kayit yoksa da 404.
            return ApiCevap::hata('Randevu bulunamadı', 404);
        }

        $this->kapi->denetle($user->id, $salonId, [
            'randevu.takvim_gor',
            'randevu.olustur',
            'randevu.duzenle_iptal',
        ]);

        $telGor = $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'musteri.telefon_gor');

        return ApiCevap::basarili($this->servis->detay($id, $telGor));
    }
}
