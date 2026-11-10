<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Tahsilat\TahsilatNet;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\YetkisizErisimException;
use App\Models\Personeller;
use App\Models\Tahsilat;
use App\Services\Ayar\MusteriIndirimServisi;
use App\Services\Tahsilat\AdisyonOkumaServisi;
use App\Services\Tahsilat\TahsilatSilServisi;
use App\Services\Tahsilat\TahsilatYazServisi;
use App\Services\Tahsilat\TaksitServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\HesapSahibiOnayi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tahsilat YAZMA API v2 — şimdilik yalnızca DRY-RUN.
 * KVKK: kimlik + kapsam (adisyon bu işletmeye mi ait) + satis.tahsilat_al.
 * Gerçek yazma KAPALI (dry_run=1 zorunlu); kullanıcı onayıyla açılacak.
 */
class TahsilatController extends V2Controller
{
    protected ?string $yazmaAlani = 'tahsilat';

    public function __construct(
        private TahsilatYazServisi $servis,
        private TahsilatSilServisi $silServisi,
        private AdisyonOkumaServisi $adisyonServisi,
        private GuvenlikKapisi $kapi,
        private HesapSahibiOnayi $onay,
    ) {}

    /** POST /api/v2/tahsilat */
    public function ekle(Request $request)
    {
        $user = $this->kullanici();

        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        $adisyonId = (int) $request->input('adisyon_id');
        if (! $adisyonId) {
            return ApiCevap::hata('adisyon_id zorunlu', 422);
        }

        // Tenant izolasyonu: adisyon GERÇEKTEN bu salona mı ait?
        $adisyonSalon = $this->adisyonServisi->salonIdGetir($adisyonId);
        if (! $adisyonSalon) {
            return ApiCevap::hata('Adisyon bulunamadı', 404);
        }
        if ($adisyonSalon !== $salonId) {
            throw new YetkisizErisimException('Adisyon bu işletmeye ait değil');
        }

        // Kapsam + yetki.
        $this->kapi->denetle($user->id, $salonId, ['satis.tahsilat_al']);
        $this->vadelerSalonun($salonId, (array) $request->input('taksit_vade_id', []), 'taksit_vadeleri', 'taksitli_tahsilatlar', 'taksitli_tahsilat_id');
        $this->vadelerSalonun($salonId, (array) $request->input('senet_vade_id', []), 'senet_vadeleri', 'senetler', 'senet_id');

        // GERÇEK YAZMA yolu — yalnızca dry_run=false iken.
        if (! $request->boolean('dry_run')) {
            // Çift güvenlik: config bayrağı kapalıysa asla yazma.
            $this->yazmaAcikMi('Gerçek yazma henüz etkin değil (dry_run=1 zorunlu).');
            // Harici indirim varsa ek yetki: satis.indirim_uygula. Müşterinin hak ettiği aktif/sadık indirim payı
            // (brüt = tutar + indirim üzerinden %) yetkisiz uygulanabilir — eski ekranda kendiliğinden düşüyordu.
            $indirim = (float) $request->input('indirim', 0);
            if ($indirim > 0 && ! $this->musteriIndirimiIcinde($salonId, $adisyonId, (float) $request->input('tutar', 0), $indirim)) {
                $this->kapi->denetle($user->id, $salonId, ['satis.indirim_uygula']);
            }
            // Taksit/senet vade ödemesi + harici indirim: ARTIK destekleniyor.
            if (! $request->filled('idempotency_key')) {
                return ApiCevap::hata('idempotency_key zorunlu', 422);
            }

            $olusturanId = Personeller::where('salon_id', $salonId)
                ->where('yetkili_id', $user->id)->value('id');

            $sonuc = $this->servis->yaz(
                array_merge($request->all(), ['salon_id' => $salonId, 'adisyon_id' => $adisyonId]),
                ['yetkili_id' => $user->id, 'salon_id' => $salonId, 'olusturan_id' => $olusturanId ? (int) $olusturanId : null]
            );

            $sonuc['hesap'] = $this->adisyonServisi->getir($adisyonId)['hesap'];

            return ApiCevap::basarili(
                $sonuc,
                ($sonuc['replay'] ?? false) ? 'Bu istek zaten işlenmişti (replay — yeni kayıt yok).' : 'Tahsilat kaydedildi.'
            );
        }

        // DRY-RUN yolu — hiçbir şey yazmaz.
        $plan = $this->servis->planla(array_merge($request->all(), [
            'salon_id'   => $salonId,
            'adisyon_id' => $adisyonId,
        ]));

        return ApiCevap::basarili($plan, 'DRY-RUN: hiçbir şey yazılmadı.');
    }

    /**
     * Ödenecek vadeler bu işletmenin planına ait ve ödenmemiş olmalı. Denetim yokken başka salonun vadesi kapatılabilir,
     * ödenmiş vade ikinci kez dağıtılabilirdi.
     */
    private function vadelerSalonun(int $salonId, array $ham, string $vadeTablo, string $planTablo, string $fk): void
    {
        $idler = array_values(array_unique(array_filter(array_map('intval', $ham))));
        if (! $idler) {
            return;
        }
        $gecerli = DB::table($vadeTablo.' as v')->join($planTablo.' as p', 'p.id', '=', 'v.'.$fk)
            ->whereIn('v.id', $idler)->where('p.salon_id', $salonId)->where(fn ($q) => $q->where('v.odendi', 0)->orWhereNull('v.odendi'))
            ->count();
        if ($gecerli !== count($idler)) {
            throw new GecersizIslemException('Seçilen vade bulunamadı ya da zaten ödenmiş.');
        }
    }

    /**
     * POST /api/v2/adisyon/{id}/taksit — "Taksit Yap" (eski taksitekleguncelle): ön ödeme (isteğe bağlı) normal tahsilat
     * olarak yazılır, adisyonun kalan borcu aylık taksitlere bölünür. Yetki eskisi gibi satis.senet_olustur; ön ödeme
     * alınıyorsa satis.tahsilat_al da gerekir.
     */
    public function taksit(Request $request, int $id, TaksitServisi $taksit)
    {
        $user = $this->kullanici();
        $salonId = $this->adisyonServisi->salonIdGetir($id);
        if (! $salonId) {
            return ApiCevap::hata('Adisyon bulunamadı', 404);
        }
        $onOdeme = (float) $request->input('on_odeme', 0);
        $this->kapi->denetle($user->id, $salonId, ['satis.senet_olustur']);
        if ($onOdeme > 0) {
            $this->kapi->denetle($user->id, $salonId, ['satis.tahsilat_al']);
        }
        $this->yazmaAcikMi();
        if ($onOdeme > 0 && ! $request->filled('on_odeme_yontemi_id')) {
            return ApiCevap::hata('Ön ödeme türü seçin', 422);
        }
        if (! $request->filled('idempotency_key')) {
            return ApiCevap::hata('idempotency_key zorunlu', 422);
        }

        $olusturanId = Personeller::where('salon_id', $salonId)->where('yetkili_id', $user->id)->value('id');
        $sonuc = $taksit->adisyonTaksiti($id,
            $request->only(['on_odeme', 'on_odeme_yontemi_id', 'odeme_tarihi', 'vade', 'vade_baslangic_tarihi', 'notlar', 'idempotency_key']),
            ['yetkili_id' => $user->id, 'salon_id' => $salonId, 'olusturan_id' => $olusturanId ? (int) $olusturanId : null]);
        $sonuc['hesap'] = $this->adisyonServisi->getir($id)['hesap'];

        return ApiCevap::basarili($sonuc, $sonuc['replay'] ? 'Bu istek zaten işlenmişti.' : 'Taksitli alacak kayıtları oluşturuldu.');
    }

    /**
     * PATCH /api/v2/tahsilat/{id} {onay_kodu?} — tahsilat DÜZENLE (eski kaydı iade et + yeniyi yaz, atomik).
     * KVKK: kimlik + kapsam (tahsilatın salonu) + satis.tahsilat_al; tutar düşüyorsa satis.tahsilat_sil + SMS ayarı 23
     * hesap sahibi onayı (428 → kodla tekrar). Flag korumalı.
     */
    public function guncelle(Request $request, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $t = Tahsilat::find($id);
        if (! $t) {
            return ApiCevap::hata('Tahsilat bulunamadı', 404);
        }
        $salonId = (int) $t->salon_id;
        $adisyonId = (int) $t->adisyon_id;

        $this->kapi->denetle($user->id, $salonId, ['satis.tahsilat_al']);

        $tutar = (float) $request->input('tutar', 0);
        if ($tutar <= 0) {
            return ApiCevap::hata('tutar 0’dan büyük olmalı', 422);
        }
        if (! $request->filled('idempotency_key')) {
            return ApiCevap::hata('idempotency_key zorunlu', 422);
        }
        // Kullanilmis anahtarda yaz() replay doner ve yeni kayit yazmaz; once silme yapilirsa tahsilat onaysiz kaybolur.
        // Gecerli tekrar denemede eski kayit zaten silindigi icin yukarida 404 doner; buraya gelen kullanilmis anahtar reddedilir.
        if ($this->servis->anahtarKullanildi((string) $request->input('idempotency_key'))) {
            return ApiCevap::hata('Bu istek anahtarı daha önce kullanıldı; yeni bir anahtarla tekrar deneyin.', 409);
        }
        // NET dusuyorsa (yeni tutar - komisyon < eski tahsilatlar.tutar; yazmayla ayni tanim) fiilen kismi silme:
        // satis.tahsilat_sil + SMS ayari 23 hesap sahibi onayi (silmeyle ortak kapi).
        $komisyon = (float) $request->input('komisyon', 0);
        if (TahsilatNet::azaltmaMi((float) $t->tutar, $tutar, $komisyon)) {
            $this->kapi->denetle($user->id, $salonId, ['satis.tahsilat_sil']);
            $this->onay->tahsilatAzaltma($salonId, (int) $user->id, $id, $tutar, $request->input('onay_kodu'), $komisyon);
        }

        $olusturanId = Personeller::where('salon_id', $salonId)
            ->where('yetkili_id', $user->id)->value('id');

        // Atomik: önce eski kaydı iade et, sonra yeniyi yaz (kalan doğru yeniden dağıtılır).
        $sonuc = DB::transaction(function () use ($id, $request, $salonId, $adisyonId, $user, $olusturanId) {
            $this->silServisi->sil($id, ['yetkili_id' => $user->id, 'salon_id' => $salonId]);

            return $this->servis->yaz(
                array_merge($request->all(), ['salon_id' => $salonId, 'adisyon_id' => $adisyonId]),
                ['yetkili_id' => $user->id, 'salon_id' => $salonId, 'olusturan_id' => $olusturanId ? (int) $olusturanId : null]
            );
        });

        $sonuc['hesap'] = $this->adisyonServisi->getir($adisyonId)['hesap'];

        return ApiCevap::basarili($sonuc, 'Tahsilat güncellendi.');
    }

    /**
     * DELETE /api/v2/tahsilat/{id} {onay_kodu?} — tahsilat İADE/SİLME.
     * KVKK: kimlik + kapsam (tahsilatın salonu) + satis.tahsilat_sil. Flag korumalı.
     * SMS ayarı 23: hesap sahibi olmayan personel için hesap sahibinin onay kodu (428 → kodla tekrar).
     */
    public function sil(Request $request, int $id)
    {
        $user = $this->kullanici();

        // Gerçek yazma/iade flag'i (yazma ile aynı kapı).
        $this->yazmaAcikMi('Gerçek iade/silme henüz etkin değil');

        $t = Tahsilat::find($id);
        if (! $t) {
            return ApiCevap::hata('Tahsilat bulunamadı', 404);
        }
        $salonId = (int) $t->salon_id;

        // Kapsam + yetki.
        $this->kapi->denetle($user->id, $salonId, ['satis.tahsilat_sil']);
        $this->onay->tahsilatSilme($salonId, (int) $user->id, $id, $request->input('onay_kodu'));

        $sonuc = $this->silServisi->sil($id, ['yetkili_id' => $user->id, 'salon_id' => $salonId]);
        $sonuc['hesap'] = $this->adisyonServisi->getir($sonuc['adisyon_id'])['hesap'];

        return ApiCevap::basarili($sonuc, 'Tahsilat iade edildi (silindi).');
    }

    /** İndirim, müşterinin bu adisyondaki aktif/sadık payını (brüt × %, 1 kuruş tolerans) aşmıyor mu? */
    private function musteriIndirimiIcinde(int $salonId, int $adisyonId, float $tutar, float $indirim): bool
    {
        $userId = (int) DB::table('adisyonlar')->where('id', $adisyonId)->value('user_id');
        $m = app(MusteriIndirimServisi::class)->adisyonIcin($salonId, $userId, $adisyonId);

        return $m !== null && $indirim <= MusteriIndirimServisi::pay($tutar + $indirim, $m['yuzde']) + 0.01;
    }
}
