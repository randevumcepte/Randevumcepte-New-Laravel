<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Hesabim\ProfilGuncelleIstegi;
use App\Services\Profil\ProfilServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * PROFİL v2 (eski /isletmeyonetim/profil + v1 bilgiguncelle/profilresimyukle) — ince controller.
 *
 *   GET  /profil
 *   PUT  /profil         {name, gsm1, email?, unvan?, cinsiyet?, sms_gonderimi?, password?, salon_id?}
 *   POST /profil/resim   multipart: resim (v1 adı folderPath da olur), salon_id?
 *
 * Herkes yalnız KENDİ profilini görür/değiştirir (personel dahil — Flutter'da da açık).
 * salon_id yalnız aktivite logunun bağlamıdır; yoksa kullanıcının ilk salonu.
 */
class ProfilController extends V2Controller
{
    protected ?string $yazmaAlani = 'hesabim';

    protected string $yazmaKapaliMesaj = 'Profil kaydı henüz etkin değil (HESABIM_V2_YAZMA_AKTIF kapalı)';

    public function __construct(private ProfilServisi $profil) {}

    public function goster()
    {
        return ApiCevap::basarili($this->profil->getir((int) $this->kullanici()->id));
    }

    public function guncelle(ProfilGuncelleIstegi $istek)
    {
        $this->yazmaAcikMi();
        $sifre = ! empty($istek->validated('password'));
        $veri = $this->profil->guncelle((int) $this->kullanici()->id, $istek->validated(), $this->baglamSalonu($istek), $this->aktifTokenId());

        return ApiCevap::basarili($veri, $sifre
            ? 'Bilgileriniz güncellendi. Diğer cihazlardaki oturumlarınız kapatılacak.'
            : 'Bilgileriniz güncellendi.');
    }

    public function resim(Request $request)
    {
        $this->yazmaAcikMi();
        $dosya = $request->file('resim') ?? $request->file('folderPath');

        return ApiCevap::basarili($this->profil->resimYukle((int) $this->kullanici()->id, $dosya, $this->baglamSalonu($request)), 'Profil resmi güncellendi.');
    }
}
