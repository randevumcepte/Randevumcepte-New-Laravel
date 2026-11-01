<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\CakismaException;
use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Models\Personeller;
use App\Services\Satis\AdisyonIslemServisi;
use App\Services\Satis\AdisyonSilServisi;
use App\Services\Satis\SatisListeServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Satis Takibi API v2 — ince controller. KVKK: kimlik + kapsam + yetki.
 * Yetki kurallari eski StoreAdminController ile BIREBIR:
 *   liste   : satis.adisyon_olustur | satis.tahsilat_al | satis.tum_satis_gor (OR)
 *   sil     : satis.adisyon_sil
 *   fatura  : salon_personelleri.role_id = 1 (hesap sahibi), studyo degil
 *   odeme   : satis.tahsilat_al (yalniz studyo modu)
 * Yazmalar TAHSILAT_V2_YAZMA_AKTIF kill-switch arkasinda (kapaliyken 409).
 */
class SatisController extends Controller
{
    private const LISTE_YETKI = ['satis.tum_satis_gor', 'satis.adisyon_olustur', 'satis.tahsilat_al'];

    public function __construct(
        private SatisListeServisi $servis,
        private AdisyonSilServisi $silServisi,
        private AdisyonIslemServisi $islem,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
    ) {}

    /**
     * GET /api/v2/satislar — satis (adisyon) listesi + ozet. Parametreler query
     * veya gövdeden (input) okunur; eski v1 isimleri de kabul edilir (parite).
     *   salon_id (zorunlu), tarih1, tarih2, tur/adisyonturu (0-3),
     *   musteri_id/musteriid, personel_id, acik_kapali/acikKapali (1 acik/0 kapali),
     *   fatura (1/0), musteriMi, appBundle, page, per_page.
     * Cevapta `yetkiler` blogu: ekranlar (web + Flutter) buton gorunurlugunu buradan alir.
     */
    public function index(Request $request)
    {
        $user = $this->kullanici();

        $salonId = (int) ($request->input('salon_id') ?: $request->input('salonid'));
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        $this->kapi->denetle($user->id, $salonId, self::LISTE_YETKI);

        // Personel kapsami: 'satis.tum_satis_gor' yoksa YALNIZCA kendi satislari.
        // (Musteri karti + 'musteri.gecmis_satis_gor' istisnasi: kisit kalkar.)
        $kisit = null;
        $musteriId = $request->input('musteri_id') ?: $request->input('musteriid');
        $personel = Personeller::where('yetkili_id', $user->id)->where('salon_id', $salonId)->first(['id', 'role_id']);
        if (! $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'satis.tum_satis_gor')) {
            $gecmisGor = $musteriId
                && $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'musteri.gecmis_satis_gor');
            if (! $gecmisGor && $personel) {
                $kisit = (int) $personel->id;
            }
        }

        // personel.kapali_adisyon_gizle: personel kaydi olan kullanici kapali
        // adisyonlari goremez → yalniz acik + kapali sayisi 0 (eski mobil kural).
        $acikKapali = $request->input('acik_kapali', $request->input('acikKapali'));
        $kapaliGizle = $personel
            && $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'personel.kapali_adisyon_gizle');
        if ($kapaliGizle) {
            $acikKapali = '1';
        }

        $veri = $this->servis->liste($salonId, [
            'tarih1'            => $request->input('tarih1'),
            'tarih2'            => $request->input('tarih2'),
            'adisyonturu'       => $request->input('tur', $request->input('adisyonturu', 0)),
            'musteri_id'        => $musteriId,
            'personel_id'       => $request->input('personel_id'),
            'acik_kapali'       => $acikKapali,
            'fatura'            => $request->input('fatura'),
            'musteriMi'         => filter_var($request->input('musteriMi'), FILTER_VALIDATE_BOOLEAN),
            'appBundle'         => $request->input('appBundle'),
            'page'              => $request->input('page'),
            'per_page'          => $request->input('per_page'),
            'kisit_personel_id' => $kisit,
        ]);
        if ($kapaliGizle) {
            $veri['kapali_sayisi'] = 0;
        }

        $studyo = $this->islem->studyoModu($salonId);
        $hesapSahibi = $this->islem->hesapSahibiGorunur($user->id, $salonId);
        $veri['studyo_modu'] = $studyo;
        $veri['yetkiler'] = [
            'tahsilat_al'      => $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'satis.tahsilat_al'),
            'adisyon_sil'      => $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'satis.adisyon_sil'),
            'fatura_isaretle'  => $hesapSahibi && ! $studyo,
            'faturasiz_gizle'  => $hesapSahibi && ! $studyo,
            'tum_satis_gor'    => $kisit === null,
            'kapali_gizli'     => (bool) $kapaliGizle,
        ];
        $veri['faturasiz_gizle_aktif'] = (bool) DB::table('salonlar')->where('id', $salonId)->value('faturasiz_gizle');

        return ApiCevap::basarili($veri);
    }

    /** DELETE /api/v2/adisyon/{id}  (satis.adisyon_sil) — eski adisyon_sil kaskadi. */
    public function sil(Request $request, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->islem->adisyonSalonId($id);
        if (! $salonId) {
            return ApiCevap::hata('Adisyon bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, ['satis.adisyon_sil']);

        $sonuc = $this->silServisi->sil($id, [
            'yetkili_id' => (int) $user->id,
            'yetkili_ad' => $user->name ?? null,
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return ApiCevap::basarili($sonuc, 'Adisyon kaydı başarıyla kaldırıldı');
    }

    /** PATCH /api/v2/adisyon/{id}/fatura — yalniz hesap sahibi (personel role_id=1), studyo degil. */
    public function fatura(int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->islem->adisyonSalonId($id);
        if (! $salonId) {
            return ApiCevap::hata('Adisyon bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, self::LISTE_YETKI);
        if (! $this->islem->hesapSahibiIslem($user->id, $salonId) || $this->islem->studyoModu($salonId)) {
            throw new YetkisizErisimException('Bu işlem için yetkiniz yok.');
        }

        $personelId = $this->islem->personel($user->id, $salonId)->id ?? null;

        return ApiCevap::basarili($this->islem->faturaIsaretle($id, $salonId, $personelId ? (int) $personelId : null));
    }

    /** PATCH /api/v2/satislar/faturasiz-gizle?salon_id= — salon geneli ayar (hesap sahibi). */
    public function faturasizGizle(Request $request)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }
        $this->kapi->denetle($user->id, $salonId, self::LISTE_YETKI);
        if (! $this->islem->hesapSahibiIslem($user->id, $salonId)) {
            throw new YetkisizErisimException('Bu işlem için yetkiniz yok.');
        }

        return ApiCevap::basarili($this->islem->faturasizGizle($salonId));
    }

    /** PATCH /api/v2/adisyon/{id}/odeme  body:{alindi:bool} — studyo "Ödeme Alındı / Geri Al" (satis.tahsilat_al). */
    public function odeme(Request $request, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->islem->adisyonSalonId($id);
        if (! $salonId) {
            return ApiCevap::hata('Adisyon bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, ['satis.tahsilat_al']);
        if (! $this->islem->studyoModu($salonId)) {
            return ApiCevap::hata('Yalnızca stüdyo modunda kullanılabilir', 422);
        }

        $olusturan = $this->islem->personel($user->id, $salonId)->id ?? null;
        $alindi = filter_var($request->input('alindi'), FILTER_VALIDATE_BOOLEAN);

        return ApiCevap::basarili($this->islem->studyoOdeme($id, $salonId, $alindi, $olusturan ? (int) $olusturan : null));
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
