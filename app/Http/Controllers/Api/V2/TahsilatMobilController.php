<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Tahsilat\MobilTahsilatHesabi;
use App\Exceptions\YetkisizErisimException;
use App\Services\OnGorusme\OnGorusmeSorgusu;
use App\Services\Tahsilat\Mobil\MobilAlacakOkumaServisi;
use App\Services\Tahsilat\Mobil\MobilHariciTahsilatServisi;
use App\Services\Tahsilat\Mobil\MobilHizliSatisServisi;
use App\Services\Tahsilat\Mobil\MobilKampanyaKoduServisi;
use App\Services\Tahsilat\Mobil\MobilOngorusmeSatisServisi;
use App\Services\Tahsilat\Mobil\MobilTahsilatServisi;
use App\Services\Tahsilat\Mobil\MobilTaksitServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\Yetki\IslemYetkileri;
use Illuminate\Http\Request;

/**
 * Eski mobil para uclarinin v2 karsiligi — ince controller. Mobil tutarlar web'den farkli bir kuralla (eski
 * ApiController) hesaplandigi icin ayri uclardir; web uclarina ve hesabina dokunulmaz.
 *
 * Her uc `mobil=1` ister. Bayrak config('oturum.mobil_bayraklari') listesindedir: imzali mobil token disinda
 * MobilBayraklar silerek getirir, istek 403 olur. Yetki web ile AYNI kural (2026-11-12): her uc ayni isin web ucunun
 * istedigi anahtari ister (IslemYetkileri); kimlikler servislerde MobilKapsam ile salona baglanir. Sira: mobil bayragi,
 * kimlik, kapsam, yetki; yazmada en son kill-switch (yetkisiz istek bayrak durumunu ogrenmez).
 */
class TahsilatMobilController extends V2Controller
{
    protected ?string $yazmaAlani = 'tahsilat';

    public function __construct(private GuvenlikKapisi $kapi) {}

    /**
     * Mobil bayragi + kimlik + kapsam + yetki; yazmada ardindan kill-switch.
     *
     * @param  array<int, array<string>>  $gruplar  her grup ayri denetlenir (gruplar VE, grup ici VEYA)
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    private function giris(Request $request, array $gruplar, bool $yazma = false): array
    {
        $this->mobilOlmali($request);
        [$uid, $salonId] = $this->salonDenetle($request, array_shift($gruplar));
        foreach ($gruplar as $yetki) {
            $this->kapi->denetle($uid, $salonId, $yetki);
        }
        if ($yazma) {
            $this->yazmaAcikMi();
        }

        return [$uid, $salonId];
    }

    /** POST /mobil-para/tahsilat (eski tahsilatekle) */
    public function tahsilat(Request $request, MobilTahsilatServisi $s)
    {
        [$uid, $salonId] = $this->giris($request, [IslemYetkileri::TAHSILAT], true);

        return ApiCevap::basarili($s->ekle($salonId, $uid, $request->all()));
    }

    /** POST /mobil-para/taksit (eski taksitekleguncelle) — web gibi: taksit yetkisi; pesin odeme varsa tahsilat yetkisi de. */
    public function taksit(Request $request, MobilTaksitServisi $s)
    {
        $pesin = MobilTahsilatHesabi::sifirdanBuyukMu($request->input('indirimli_toplam_tahsilat_tutari'));
        [$uid, $salonId] = $this->giris($request, $pesin ? [IslemYetkileri::TAKSIT, IslemYetkileri::TAHSILAT] : [IslemYetkileri::TAKSIT], true);

        return ApiCevap::basarili($s->ekle($salonId, $uid, $request->all()));
    }

    /** GET /mobil-para/alacaklar */
    public function alacaklar(Request $request, MobilAlacakOkumaServisi $s)
    {
        [, $salonId] = $this->giris($request, [IslemYetkileri::ALACAK]);

        return ApiCevap::basarili($s->alacaklar($salonId, $request->query('ara'), max(1, $request->integer('page', 1))));
    }

    /** GET /mobil-para/alacaklar-v2 */
    public function alacaklarV2(Request $request, MobilAlacakOkumaServisi $s)
    {
        [, $salonId] = $this->giris($request, [IslemYetkileri::ALACAK]);

        return ApiCevap::basarili($s->alacaklarV2($salonId, $request->query('ara'), max(1, $request->integer('page', 1))));
    }

    /** GET /mobil-para/tum-alacaklar — tek musteri/adisyonun kalan kalemleri (tahsilat, paket yukselt): adisyon gorme yetkisi. */
    public function tumAlacaklar(Request $request, MobilAlacakOkumaServisi $s)
    {
        [, $salonId] = $this->giris($request, [IslemYetkileri::SATIS_GOR]);

        return ApiCevap::basarili($s->tumAlacaklar($salonId, $request->query('musteri_id'), $request->query('adisyon_id')));
    }

    /** GET /mobil-para/senetler */
    public function senetler(Request $request, MobilAlacakOkumaServisi $s)
    {
        [, $salonId] = $this->giris($request, [IslemYetkileri::ALACAK]);

        return ApiCevap::basarili($s->senetler($salonId, $request->query('ara'), $request->query('durum'), max(1, $request->integer('page', 1))));
    }

    /** GET /mobil-para/harici-kalemler — satis katalogu (web /katalog ile ayni yetki). */
    public function hariciKalemler(Request $request, MobilHariciTahsilatServisi $s)
    {
        [, $salonId] = $this->giris($request, [IslemYetkileri::SATIS_GOR]);

        return ApiCevap::basarili($s->katalog($salonId));
    }

    /** POST /mobil-para/harici — satis acar ve tahsil eder: adisyon olusturma VE tahsilat yetkisi. */
    public function hariciEkle(Request $request, MobilHariciTahsilatServisi $s)
    {
        [$uid, $salonId] = $this->giris($request, [IslemYetkileri::ADISYON_DUZENLE, IslemYetkileri::TAHSILAT], true);

        return ApiCevap::basarili($s->ekle($salonId, $uid, $request->all()));
    }

    /** GET /mobil-para/kampanya-kodlari */
    public function kampanyaKodlari(Request $request, MobilKampanyaKoduServisi $s)
    {
        [, $salonId] = $this->giris($request, [IslemYetkileri::SATIS_GOR]);

        return ApiCevap::basarili($s->kodlar($salonId, $request->integer('user_id')));
    }

    /** POST /mobil-para/kampanya-kod-kullan */
    public function kampanyaKodKullan(Request $request, MobilKampanyaKoduServisi $s)
    {
        [$uid, $salonId] = $this->giris($request, [IslemYetkileri::TAHSILAT], true);

        return ApiCevap::basarili($s->kullan($salonId, $uid, $request->all()));
    }

    /** GET /mobil-para/kampanya-kod-durum */
    public function kampanyaKodDurum(Request $request, MobilKampanyaKoduServisi $s)
    {
        [, $salonId] = $this->giris($request, [IslemYetkileri::SATIS_GOR]);

        return ApiCevap::basarili($s->adisyonDurumu($salonId, $request->integer('adisyon_id')));
    }

    /** POST /mobil-para/ongorusme/{id}/satis (eski ongorusmesatisyapildi) — web satis-yapildi ile ayni yetki ve kayit erisimi. */
    public function ongorusmeSatis(Request $request, int $id, MobilOngorusmeSatisServisi $s, OnGorusmeSorgusu $gorusme)
    {
        $this->mobilOlmali($request);
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu($s->salonId($id), 'Ön görüşme bulunamadı');
        $this->kapi->denetle($uid, $salonId, OnGorusmeController::YAZ);
        $gorusme->erisimZorunlu($id, $uid, $salonId);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($s->satisYapildi($id, $uid, $request->all()));
    }

    /** POST /mobil-para/hizli-satis (eski stok/hizli-satis) — satisla birlikte tahsil eder: urun satisi VE tahsilat (web stok hizli satis + tahsilat). */
    public function hizliSatis(Request $request, MobilHizliSatisServisi $s)
    {
        [$uid, $salonId] = $this->giris($request, [IslemYetkileri::URUN_SAT, IslemYetkileri::TAHSILAT], true);

        return ApiCevap::basarili($s->stokSatisi($salonId, $uid, $request->all()));
    }

    /** GET /mobil-para/cagri-kalemler (eski cagri-merkezi/satis-kalemler: yalniz Premium salon) */
    public function cagriKalemler(Request $request, MobilHizliSatisServisi $s)
    {
        [, $salonId] = $this->giris($request, [IslemYetkileri::SATIS_GOR]);
        if (! $s->salonPremiumMu($salonId)) {
            throw new YetkisizErisimException('Çağrı merkezi bu işletmenin paketinde yok');
        }

        return ApiCevap::basarili($s->cagriKalemleri($salonId));
    }

    /** POST /mobil-para/cagri-hizli-satis (eski cagri-merkezi/hizli-satis: satis.tahsilat_al) */
    public function cagriHizliSatis(Request $request, MobilHizliSatisServisi $s)
    {
        [$uid, $salonId] = $this->giris($request, [IslemYetkileri::TAHSILAT], true);

        return ApiCevap::basarili($s->cagriSatisi($salonId, $uid, $request->all()));
    }
}
