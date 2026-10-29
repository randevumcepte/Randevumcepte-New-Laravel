<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Models\Personeller;
use App\Services\Tahsilat\AdisyonOkumaServisi;
use App\Services\Tahsilat\AlacakServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Planlanan alacak (receivable) API v2 — Satış & Tahsilat ekranı "Alacak Oluştur".
 * KVKK: kimlik + kapsam (adisyon bu işletmeye mi ait) + finans.alacak_yonet.
 * Yazma flag korumalı (tahsilat.yazma_aktif ile aynı kapı).
 */
class AlacakController extends Controller
{
    public function __construct(
        private AlacakServisi $servis,
        private AdisyonOkumaServisi $adisyonServisi,
        private GuvenlikKapisi $kapi,
    ) {}

    /** POST /api/v2/alacak  { salon_id, adisyon_id, tutar, planlanan_odeme_tarihi?, aciklama? } */
    public function ekle(Request $request)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        $salonId = (int) $request->input('salon_id');
        $adisyonId = (int) $request->input('adisyon_id');
        if (! $salonId || ! $adisyonId) {
            return ApiCevap::hata('salon_id ve adisyon_id zorunlu', 422);
        }
        $tutar = (float) $request->input('tutar', 0);
        if ($tutar <= 0) {
            return ApiCevap::hata('tutar 0’dan büyük olmalı', 422);
        }

        // Tenant izolasyonu: adisyon gerçekten bu salona mı ait?
        $adisyonSalon = $this->adisyonServisi->salonIdGetir($adisyonId);
        if (! $adisyonSalon) {
            return ApiCevap::hata('Adisyon bulunamadı', 404);
        }
        if ($adisyonSalon !== $salonId) {
            throw new YetkisizErisimException('Adisyon bu işletmeye ait değil');
        }

        // Kapsam + yetki.
        $this->kapi->denetle($user->id, $salonId, ['finans.alacak_yonet']);

        // Yazma flag'i (tahsilat ile aynı kapı).
        if (! config('tahsilat.yazma_aktif')) {
            return ApiCevap::hata('Gerçek yazma henüz etkin değil', 409);
        }

        $olusturanId = Personeller::where('salon_id', $salonId)
            ->where('yetkili_id', $user->id)->value('id');

        $sonuc = $this->servis->olustur(
            $adisyonId,
            $tutar,
            $request->input('planlanan_odeme_tarihi'),
            $request->input('aciklama'),
            ['salon_id' => $salonId, 'olusturan_id' => $olusturanId ? (int) $olusturanId : null]
        );

        return ApiCevap::basarili($sonuc, 'Alacak oluşturuldu.');
    }

    /** DELETE /api/v2/alacak/{id} — planlanan alacağı iptal et. */
    public function sil(int $id)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }
        if (! config('tahsilat.yazma_aktif')) {
            return ApiCevap::hata('Gerçek yazma henüz etkin değil', 409);
        }

        $salonId = $this->servis->alacakSalonId($id);
        if (! $salonId) {
            return ApiCevap::hata('Alacak bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, ['finans.alacak_yonet']);

        return ApiCevap::basarili($this->servis->sil($id), 'Alacak iptal edildi.');
    }
}
