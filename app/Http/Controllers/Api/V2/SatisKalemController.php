<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\CakismaException;
use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Satis\KalemServisi;
use App\Services\Tahsilat\AdisyonOkumaServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Satış kalem yönetimi (Satış & Tahsilat Düzenleme). Flag + KVKK (kimlik+kapsam+yetki).
 * Gerçek yazma TAHSILAT_V2_YAZMA_AKTIF arkasında (tüm v2 yazmalar birlikte kontrol).
 */
class SatisKalemController extends Controller
{
    private const DUZENLEME_YETKI = ['satis.adisyon_olustur'];

    public function __construct(
        private KalemServisi $servis,
        private AdisyonOkumaServisi $adisyonServisi,
        private GuvenlikKapisi $kapi,
    ) {}

    /** POST /api/v2/adisyon/{adisyon}/kalem  body: {tip, katalog_id, adet?, personel_id?} */
    public function ekle(Request $request, int $adisyon)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->adisyonServisi->salonIdGetir($adisyon);
        if (! $salonId) {
            return ApiCevap::hata('Adisyon bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, self::DUZENLEME_YETKI);

        $tip = (string) $request->input('tip');
        if (! in_array($tip, ['hizmet', 'urun', 'paket'], true)) {
            return ApiCevap::hata('Geçersiz tip (hizmet|urun|paket)', 422);
        }
        $katalogId = (int) $request->input('katalog_id');
        if (! $katalogId) {
            return ApiCevap::hata('katalog_id zorunlu', 422);
        }

        $sonuc = $this->servis->ekle($adisyon, $tip, $katalogId, [
            'adet'        => $request->input('adet'),
            'personel_id' => $request->input('personel_id'),
        ]);
        $sonuc['hesap'] = $this->adisyonServisi->getir($adisyon)['hesap'];

        return ApiCevap::basarili($sonuc, 'Kalem eklendi.');
    }

    /** PATCH /api/v2/kalem/urun/{id}  body: {adet} */
    public function urunAdet(Request $request, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->servis->kalemSalonId('urun', $id);
        if (! $salonId) {
            return ApiCevap::hata('Ürün kalemi bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, self::DUZENLEME_YETKI);

        $adet = (int) $request->input('adet');
        if ($adet < 1) {
            return ApiCevap::hata('adet en az 1 olmalı', 422);
        }

        $sonuc = $this->servis->urunAdetGuncelle($id, $adet);
        $sonuc['hesap'] = $this->adisyonServisi->getir($sonuc['adisyon_id'])['hesap'];

        return ApiCevap::basarili($sonuc, 'Adet güncellendi.');
    }

    /** DELETE /api/v2/kalem/{tip}/{id} */
    public function sil(int|string $tip, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $tip = (string) $tip;
        $salonId = $this->servis->kalemSalonId($tip, $id);
        if (! $salonId) {
            return ApiCevap::hata('Kalem bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, self::DUZENLEME_YETKI);

        $sonuc = $this->servis->sil($tip, $id);
        $sonuc['hesap'] = $this->adisyonServisi->getir($sonuc['adisyon_id'])['hesap'];

        return ApiCevap::basarili($sonuc, 'Kalem silindi.');
    }

    private function kullanici()
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        return $user;
    }

    private function yazmaAcikMi(): void
    {
        if (! config('tahsilat.yazma_aktif')) {
            throw new CakismaException('Düzenleme henüz etkin değil');
        }
    }
}
