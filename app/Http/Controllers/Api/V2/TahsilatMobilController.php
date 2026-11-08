<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Services\Tahsilat\Mobil\MobilAlacakOkumaServisi;
use App\Services\Tahsilat\Mobil\MobilHariciTahsilatServisi;
use App\Services\Tahsilat\Mobil\MobilHizliSatisServisi;
use App\Services\Tahsilat\Mobil\MobilKampanyaKoduServisi;
use App\Services\Tahsilat\Mobil\MobilOngorusmeSatisServisi;
use App\Services\Tahsilat\Mobil\MobilTahsilatServisi;
use App\Services\Tahsilat\Mobil\MobilTaksitServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Eski mobil para uclarinin v2 karsiligi — ince controller. Mobil tutarlar web'den farkli bir kuralla (eski
 * ApiController) hesaplandigi icin ayri uclardir; web uclarina ve hesabina dokunulmaz.
 *
 * Her uc `mobil=1` ister. Bayrak config('oturum.mobil_bayraklari') listesindedir: imzali mobil token disinda
 * MobilBayraklar silerek getirir, istek 403 olur. Yetki: eski mobilde arama yoktu, rol calismaya devam eder; kimlik ve
 * salon kapsami her zaman zorunlu, kimlikler servislerde MobilKapsam ile salona baglanir. Istisna eski kodda da yetki
 * aranan telefonda satis (satis.tahsilat_al).
 */
class TahsilatMobilController extends V2Controller
{
    protected ?string $yazmaAlani = 'tahsilat';

    public function __construct(private GuvenlikKapisi $kapi) {}

    /** @return array{0:int,1:int} [yetkili id, salon id] */
    private function giris(Request $request, bool $yazma = false): array
    {
        $this->mobilOlmali($request);
        [$uid, $salonId] = $this->salonDenetle($request, []);
        if ($yazma) {
            $this->yazmaAcikMi();
        }

        return [$uid, $salonId];
    }

    private function kayitGirisi(Request $request, ?int $salonId): int
    {
        $this->mobilOlmali($request);
        $uid = (int) $this->kullanici()->id;
        $this->kapi->kapsamDenetle($uid, $this->bulundu($salonId));
        $this->yazmaAcikMi();

        return $uid;
    }

    /** POST /mobil-para/tahsilat (eski tahsilatekle) */
    public function tahsilat(Request $request, MobilTahsilatServisi $s)
    {
        [$uid, $salonId] = $this->giris($request, true);

        return ApiCevap::basarili($s->ekle($salonId, $uid, $request->all()));
    }

    /** POST /mobil-para/taksit (eski taksitekleguncelle) */
    public function taksit(Request $request, MobilTaksitServisi $s)
    {
        [$uid, $salonId] = $this->giris($request, true);

        return ApiCevap::basarili($s->ekle($salonId, $uid, $request->all()));
    }

    /** GET /mobil-para/alacaklar */
    public function alacaklar(Request $request, MobilAlacakOkumaServisi $s)
    {
        [, $salonId] = $this->giris($request);

        return ApiCevap::basarili($s->alacaklar($salonId, $request->query('ara'), max(1, $request->integer('page', 1))));
    }

    /** GET /mobil-para/alacaklar-v2 */
    public function alacaklarV2(Request $request, MobilAlacakOkumaServisi $s)
    {
        [, $salonId] = $this->giris($request);

        return ApiCevap::basarili($s->alacaklarV2($salonId, $request->query('ara'), max(1, $request->integer('page', 1))));
    }

    /** GET /mobil-para/tum-alacaklar */
    public function tumAlacaklar(Request $request, MobilAlacakOkumaServisi $s)
    {
        [, $salonId] = $this->giris($request);

        return ApiCevap::basarili($s->tumAlacaklar($salonId, $request->query('musteri_id'), $request->query('adisyon_id')));
    }

    /** GET /mobil-para/senetler */
    public function senetler(Request $request, MobilAlacakOkumaServisi $s)
    {
        [, $salonId] = $this->giris($request);

        return ApiCevap::basarili($s->senetler($salonId, $request->query('ara'), $request->query('durum'), max(1, $request->integer('page', 1))));
    }

    /** GET /mobil-para/harici-kalemler */
    public function hariciKalemler(Request $request, MobilHariciTahsilatServisi $s)
    {
        [, $salonId] = $this->giris($request);

        return ApiCevap::basarili($s->katalog($salonId));
    }

    /** POST /mobil-para/harici */
    public function hariciEkle(Request $request, MobilHariciTahsilatServisi $s)
    {
        [$uid, $salonId] = $this->giris($request, true);

        return ApiCevap::basarili($s->ekle($salonId, $uid, $request->all()));
    }

    /** GET /mobil-para/kampanya-kodlari */
    public function kampanyaKodlari(Request $request, MobilKampanyaKoduServisi $s)
    {
        [, $salonId] = $this->giris($request);

        return ApiCevap::basarili($s->kodlar($salonId, $request->integer('user_id')));
    }

    /** POST /mobil-para/kampanya-kod-kullan */
    public function kampanyaKodKullan(Request $request, MobilKampanyaKoduServisi $s)
    {
        [$uid, $salonId] = $this->giris($request, true);

        return ApiCevap::basarili($s->kullan($salonId, $uid, $request->all()));
    }

    /** GET /mobil-para/kampanya-kod-durum */
    public function kampanyaKodDurum(Request $request, MobilKampanyaKoduServisi $s)
    {
        [, $salonId] = $this->giris($request);

        return ApiCevap::basarili($s->adisyonDurumu($salonId, $request->integer('adisyon_id')));
    }

    /** POST /mobil-para/ongorusme/{id}/satis (eski ongorusmesatisyapildi) */
    public function ongorusmeSatis(Request $request, int $id, MobilOngorusmeSatisServisi $s)
    {
        $uid = $this->kayitGirisi($request, $s->salonId($id));

        return ApiCevap::basarili($s->satisYapildi($id, $uid, $request->all()));
    }

    /** POST /mobil-para/hizli-satis (eski stok/hizli-satis) */
    public function hizliSatis(Request $request, MobilHizliSatisServisi $s)
    {
        [$uid, $salonId] = $this->giris($request, true);

        return ApiCevap::basarili($s->stokSatisi($salonId, $uid, $request->all()));
    }

    /** GET /mobil-para/cagri-kalemler (eski cagri-merkezi/satis-kalemler: yalniz Premium salon) */
    public function cagriKalemler(Request $request, MobilHizliSatisServisi $s)
    {
        [, $salonId] = $this->giris($request);
        if (! $s->salonPremiumMu($salonId)) {
            throw new YetkisizErisimException('Çağrı merkezi bu işletmenin paketinde yok');
        }

        return ApiCevap::basarili($s->cagriKalemleri($salonId));
    }

    /** POST /mobil-para/cagri-hizli-satis (eski cagri-merkezi/hizli-satis: satis.tahsilat_al) */
    public function cagriHizliSatis(Request $request, MobilHizliSatisServisi $s)
    {
        [$uid, $salonId] = $this->giris($request, true);
        $this->kapi->denetle($uid, $salonId, ['satis.tahsilat_al']);

        return ApiCevap::basarili($s->cagriSatisi($salonId, $uid, $request->all()));
    }
}
