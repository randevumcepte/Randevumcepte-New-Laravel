<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\BulunamadiException;
use App\Exceptions\YetkisizErisimException;
use App\Models\Adisyonlar;
use App\Models\Personeller;
use App\Services\Satis\AdisyonIslemServisi;
use App\Services\Satis\AdisyonSilServisi;
use App\Services\Satis\MobilStudyoOdemeServisi;
use App\Services\Satis\SatisListeServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\HesapSahibiOnayi;
use App\Services\Yetki\KapsamServisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\AktiviteLog;
use App\Support\ApiCevap;
use App\Support\Idempotency;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Satis Takibi API v2 — ince controller. KVKK: kimlik + kapsam + yetki.
 * Yetki kurallari eski StoreAdminController ile BIREBIR:
 *   liste   : satis.adisyon_olustur | satis.tahsilat_al | satis.tum_satis_gor (OR)
 *   sil     : satis.adisyon_sil
 *   fatura  : salon_personelleri.role_id = 1 (hesap sahibi), studyo degil
 *   odeme   : satis.tahsilat_al (yalniz studyo modu)
 * Yazmalar TAHSILAT_V2_YAZMA_AKTIF kill-switch arkasinda (kapaliyken 409).
 * Mobil yol (mobil=1, yalniz imzali mobil token): eski ApiController sonucu ve kurali; kimlik + salon
 * kapsami her zaman zorunlu. Web yolu degismez.
 */
class SatisController extends V2Controller
{
    protected ?string $yazmaAlani = 'tahsilat';

    protected string $yazmaKapaliMesaj = 'Düzenleme henüz etkin değil';

    /** Tablo sıralama beyaz listesi (SatisListeServisi'ndeki ifadelerle birebir). */
    private const SIRALANABILIR = ['durum', 'tarih', 'musteri', 'yaklasan', 'indirim', 'toplam', 'odenen', 'kalan', 'odeme_tarihi'];

    private const LISTE_YETKI = ['satis.tum_satis_gor', 'satis.adisyon_olustur', 'satis.tahsilat_al'];

    public function __construct(
        private SatisListeServisi $servis,
        private AdisyonSilServisi $silServisi,
        private AdisyonIslemServisi $islem,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
        private KapsamServisi $kapsam,
        private MobilStudyoOdemeServisi $mobilOdeme,
        private HesapSahibiOnayi $onay,
    ) {}

    /**
     * GET /api/v2/satislar — satis (adisyon) listesi + ozet. Parametreler query
     * veya gövdeden (input) okunur; eski v1 isimleri de kabul edilir (parite).
     *   salon_id (zorunlu), tarih1, tarih2, tur/adisyonturu (0-3),
     *   musteri_id/musteriid, personel_id, acik_kapali/acikKapali (1 acik/0 kapali),
     *   fatura (1/0), satis_durumu (acik|kapali|faturali|faturasiz — eski paneldeki
     *   tek "Satis Durumu" secimi; verilirse acik_kapali/fatura yerine gecer),
     *   musteriMi, appBundle + ortak tablo parametreleri (ListeIstegi): page, per_page,
     *   ara (adisyon no / müşteri / hizmet-ürün-paket / personel adı), sirala (SIRALANABILIR), yon.
     * Cevap: `yetkiler` (sayfa duzeyi) + her satirda `kapali` ve `aksiyonlar` —
     * web ve Flutter durum/buton kararini YENIDEN HESAPLAMAZ (tek kaynak).
     */
    public function index(Request $request)
    {
        $user = $this->kullanici();

        $salonId = (int) ($request->input('salon_id') ?: $request->input('salonid'));
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        if ($request->boolean('mobil')) {
            return $this->mobilListe($request, (int) $user->id, $salonId);
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
        $fatura = $request->input('fatura');
        switch ((string) $request->input('satis_durumu')) {
            case 'acik':      $acikKapali = '1'; break;
            case 'kapali':    $acikKapali = '0'; break;
            case 'faturali':  $fatura = '1'; break;
            case 'faturasiz': $fatura = '0'; break;
        }
        $kapaliGizle = $personel
            && $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'personel.kapali_adisyon_gizle');
        if ($kapaliGizle) {
            $acikKapali = '1';
        }

        $studyo = $this->islem->studyoModu($salonId);
        $hesapSahibi = $this->yetki->yetkiliHesapSahibiGorunur($user->id, $salonId);
        $yetkiler = [
            'tahsilat_al'     => $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'satis.tahsilat_al'),
            'adisyon_olustur' => $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'satis.adisyon_olustur'),
            'adisyon_sil'     => $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'satis.adisyon_sil'),
            'fatura_isaretle' => $hesapSahibi && ! $studyo,
            'faturasiz_gizle' => $hesapSahibi && ! $studyo,
            'tum_satis_gor'   => $kisit === null,
            'kapali_gizli'    => (bool) $kapaliGizle,
        ];

        $veri = $this->servis->liste($salonId, [
            'tarih1'            => $request->input('tarih1'),
            'tarih2'            => $request->input('tarih2'),
            'adisyonturu'       => $request->input('tur', $request->input('adisyonturu', 0)),
            'musteri_id'        => $musteriId,
            'personel_id'       => $request->input('personel_id'),
            'acik_kapali'       => $acikKapali,
            'fatura'            => $fatura,
            'musteriMi'         => filter_var($request->input('musteriMi'), FILTER_VALIDATE_BOOLEAN),
            'appBundle'         => $request->input('appBundle'),
            'kisit_personel_id' => $kisit,
            'yetkiler'          => $yetkiler,
        // Ortak tablo sözleşmesi: page, per_page (API varsayılanı 20 — eski mobil), ara, sirala/yon.
        ] + ListeIstegi::istekten($request, 20, self::SIRALANABILIR, 'tarih', 'desc')->dizi());
        if ($kapaliGizle) {
            $veri['kapali_sayisi'] = 0;
        }

        $veri['studyo_modu'] = $studyo;
        $veri['yetkiler'] = $yetkiler;
        $veri['faturasiz_gizle_aktif'] = $this->islem->faturasizGizleAktif($salonId);

        return ApiCevap::basarili($veri);
    }

    /** DELETE /api/v2/adisyon/{id} {onay_kodu?} (satis.adisyon_sil) — eski adisyon_sil kaskadi; SMS ayari 23 hesap sahibi onayi. */
    public function sil(Request $request, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->bulundu($this->islem->adisyonSalonId($id), 'Adisyon bulunamadı');
        $this->kapi->denetle($user->id, $salonId, ['satis.adisyon_sil']);
        $this->onay->adisyonSilme($salonId, (int) $user->id, $id, $request->input('onay_kodu'));

        return ApiCevap::basarili($this->silServisi->sil($id, (int) $user->id, $request->boolean('mobil')), 'Adisyon kaydı başarıyla kaldırıldı');
    }

    /** PATCH /api/v2/adisyon/{id}/fatura — yalniz hesap sahibi (personel role_id=1), studyo degil. */
    public function fatura(Request $request, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->bulundu($this->islem->adisyonSalonId($id), 'Adisyon bulunamadı');
        $mobil = $request->boolean('mobil');
        if ($mobil) {
            $this->mobilSalonEslesmeli($request, $salonId);
            $this->kapi->kapsamDenetle($user->id, $salonId);
        } else {
            $this->kapi->denetle($user->id, $salonId, self::LISTE_YETKI);
        }
        if (! $this->yetki->yetkiliHesapSahibiIslem($user->id, $salonId) || (! $mobil && $this->islem->studyoModu($salonId))) {
            throw new YetkisizErisimException('Bu işlem için yetkiniz yok.');
        }

        $sonuc = $this->islem->faturaIsaretle($id, $salonId, $this->personelId($user->id, $salonId));
        if ($mobil) {
            AktiviteLog::yaz($salonId, (int) $user->id, 'adisyon_fatura_isaretle', 'adisyon', $id, null, 'Adisyon fatura durumu degistirildi', ['fatura_kesildi' => $sonuc['fatura_kesildi']]);
        }

        return ApiCevap::basarili($sonuc);
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
        $mobil = $request->boolean('mobil');
        $mobil ? $this->kapi->kapsamDenetle($user->id, $salonId) : $this->kapi->denetle($user->id, $salonId, self::LISTE_YETKI);
        if (! $this->yetki->yetkiliHesapSahibiIslem($user->id, $salonId)) {
            throw new YetkisizErisimException('Bu işlem için yetkiniz yok.');
        }

        $sonuc = $this->islem->faturasizGizle($salonId);
        if ($mobil) {
            AktiviteLog::yaz($salonId, (int) $user->id, 'faturasiz_gizle_toggle', 'salon', $salonId, null, 'Faturasiz gizle modu degistirildi', $sonuc);
        }

        return ApiCevap::basarili($sonuc);
    }

    /** PATCH /api/v2/adisyon/{id}/odeme  body:{alindi:bool, onay_kodu?} — studyo "Ödeme Alındı / Geri Al" (satis.tahsilat_al; geri almada SMS ayarı 23 onayı). */
    public function odeme(Request $request, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->bulundu($this->islem->adisyonSalonId($id), 'Adisyon bulunamadı');
        $alindi = filter_var($request->input('alindi'), FILTER_VALIDATE_BOOLEAN);
        if ($request->boolean('mobil')) {
            return $this->mobilOdeme($request, $id, (int) $user->id, $salonId, $alindi);
        }

        $this->kapi->denetle($user->id, $salonId, ['satis.tahsilat_al']);
        if (! $this->islem->studyoModu($salonId)) {
            return ApiCevap::hata('Yalnızca stüdyo modunda kullanılabilir', 422);
        }
        $this->odemeGeriAlmaOnayi($request, $id, (int) $user->id, $salonId, $alindi);

        return ApiCevap::basarili($this->islem->studyoOdeme($id, $salonId, $alindi, [
            'yetkili_id'   => (int) $user->id,
            'olusturan_id' => $this->personelId($user->id, $salonId),
        ]));
    }

    /** PATCH /api/v2/adisyon/{id}/odeme-tarihi  body:{tarih?:Y-m-d} — stüdyo "Ödeme Alınma Tarihi" (satis.tahsilat_al). */
    public function odemeTarihi(Request $request, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();
        $request->validate(['tarih' => ['nullable', 'date_format:Y-m-d']]);

        $salonId = $this->bulundu($this->islem->adisyonSalonId($id), 'Adisyon bulunamadı');
        $this->kapi->denetle($user->id, $salonId, ['satis.tahsilat_al']);
        if (! $this->islem->studyoModu($salonId)) {
            return ApiCevap::hata('Yalnızca stüdyo modunda kullanılabilir', 422);
        }

        return ApiCevap::basarili($this->islem->studyoOdemeTarihi($id, $salonId, $request->input('tarih'), [
            'yetkili_id'   => (int) $user->id,
            'olusturan_id' => $this->personelId($user->id, $salonId),
        ]));
    }

    /**
     * Eski mobil listesi. user_id yalniz filtre secer: rol 5 ve tum_satis_gor yetkisi yoksa baskasinin
     * satisi istenemez (musteri karti haric, mobil birlestirme onu kullanir). personel.kapali_adisyon_gizle
     * filtre kullanicisinin uzerinden okunur (eskiyle ayni). personel_id eskide de yok sayilirdi.
     */
    private function mobilListe(Request $request, int $yetkiliId, int $salonId)
    {
        $this->kapi->kapsamDenetle($yetkiliId, $salonId);

        $musteriId = $request->input('musteriid') ?: $request->input('musteri_id');
        $hedef = $this->mobilFiltreKullanicisi($yetkiliId, $salonId, (int) $request->input('user_id'), (bool) $musteriId);
        $personelId = $hedef ? $this->personelId($hedef, $salonId) : null;
        $kapaliGizle = $personelId && $this->yetki->yetkiliYetkiVar($hedef, $salonId, 'personel.kapali_adisyon_gizle');

        $veri = $this->servis->liste($salonId, [
            'mobil'             => true,
            'tarih1'            => $request->input('tarih1'),
            'tarih2'            => $request->input('tarih2'),
            'adisyonturu'       => $request->input('adisyonturu', 0),
            'musteri_id'        => $musteriId,
            'personel_id'       => null,
            'kisit_personel_id' => $personelId,
            'acik_kapali'       => $kapaliGizle ? 1 : $request->input('acikKapali', -1),
            'page'              => $request->input('page', 1),
            'per_page'          => 20,
        ]);
        if ($kapaliGizle) {
            $veri['kapali_sayisi'] = 0;
        }

        return ApiCevap::basarili($veri);
    }

    /**
     * PATCH /api/v2/adisyon/{id}/tarih  body: {tarih: Y-m-d} — satış tarihi (eski adisyonTarihGuncelle).
     * Tahsilatların ödeme tarihi de aynı güne kayar (kasa raporu ile hizalı). Rol 5 + tum_satis_gor yok → 403.
     */
    public function tarih(Request $request, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();
        $request->validate(['tarih' => ['required', 'date_format:Y-m-d']]);

        $salonId = $this->bulundu($this->islem->adisyonSalonId($id), 'Satış bulunamadı');
        $this->kapi->denetle($user->id, $salonId, self::LISTE_YETKI);
        if ($this->satisKisitli($user->id, $salonId)) {
            throw new YetkisizErisimException('Yetkisiz işlem');
        }

        return ApiCevap::basarili($this->islem->satisTarihiGuncelle($id, $salonId, (string) $request->input('tarih')), 'Satış tarihi güncellendi.');
    }

    /** Eski personelmi($r, 'satis.tum_satis_gor'): salonda Personel rolü (5) VAR ve tum_satis_gor YOK. */
    private function satisKisitli(int $yetkiliId, int $salonId): bool
    {
        return in_array(5, $this->kapsam->salonRolleri($yetkiliId, $salonId), true)
            && ! $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'satis.tum_satis_gor');
    }

    private function mobilFiltreKullanicisi(int $yetkiliId, int $salonId, int $istenen, bool $musteriVar): ?int
    {
        $sinirli = $this->satisKisitli($yetkiliId, $salonId);
        if (! $sinirli) {
            return $istenen ?: null;
        }

        return $istenen === 0 && $musteriVar ? null : $yetkiliId;
    }

    /** Eski adisyonOdemeIsaretle: salon eslesmeli, stüdyo sarti ve yetki yoktu; tekrar gonderim ikinci tahsilat yazmaz. */
    private function mobilOdeme(Request $request, int $id, int $yetkiliId, int $salonId, bool $alindi)
    {
        $this->mobilSalonEslesmeli($request, $salonId);
        $this->kapi->kapsamDenetle($yetkiliId, $salonId);
        $this->odemeGeriAlmaOnayi($request, $id, $yetkiliId, $salonId, $alindi);

        $sonuc = Idempotency::calistir('mobil_odeme', (string) $request->input('idempotency_key'), $yetkiliId, $salonId,
            fn () => ['odendi' => $this->mobilOdeme->isaretle(Adisyonlar::findOrFail($id), $alindi)]);

        return ApiCevap::basarili(['durum' => 'ok', 'odendi' => $sonuc['odendi']]);
    }

    /**
     * "Ödeme alınmadı" adisyonun tahsilatlarini siler: SMS ayari 23 hesap sahibi onayi (HesapSahibiOnayi tahsilat
     * azaltma kapisi; kod gerekiyorsa 428, istemci `onay_kodu` ile tekrarlar). Web ve mobil yol ayni cagri.
     */
    private function odemeGeriAlmaOnayi(Request $request, int $adisyonId, int $yetkiliId, int $salonId, bool $alindi): void
    {
        if (! $alindi) {
            $this->onay->odemeGeriAlma($salonId, $yetkiliId, $adisyonId, $request->input('onay_kodu'));
        }
    }

    private function mobilSalonEslesmeli(Request $request, int $salonId): void
    {
        if ($request->integer('salon_id') !== $salonId) {
            throw new BulunamadiException('Adisyon bulunamadı');
        }
    }

    /** Yetkilinin bu salondaki personel kaydi (olusturan / fatura kesen). */
    private function personelId(int $yetkiliId, int $salonId): ?int
    {
        $id = Personeller::where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)->value('id');

        return $id ? (int) $id : null;
    }
}
