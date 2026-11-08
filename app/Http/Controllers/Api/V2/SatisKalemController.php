<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Exceptions\YetkisizErisimException;
use App\Models\Adisyonlar;
use App\Models\Salonlar;
use App\Services\Paket\PaketYukseltServisi;
use App\Services\Satis\KalemServisi;
use App\Services\Satis\MobilKalemServisi;
use App\Services\Tahsilat\AdisyonOkumaServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\SalonSahipligi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use App\Support\Idempotency;
use Illuminate\Http\Request;

/**
 * Satış kalem yönetimi (Satış & Tahsilat Düzenleme). Flag + KVKK (kimlik+kapsam+yetki).
 * Gerçek yazma TAHSILAT_V2_YAZMA_AKTIF arkasında (tüm v2 yazmalar birlikte kontrol).
 */
class SatisKalemController extends V2Controller
{
    protected ?string $yazmaAlani = 'tahsilat';

    protected string $yazmaKapaliMesaj = 'Düzenleme henüz etkin değil';

    private const DUZENLEME_YETKI = ['satis.adisyon_olustur'];

    /** Fiyatı elle değiştirme (eski *tahsilattutaridegistir kapısı; v2 satış yazıcılarıyla aynı). */
    private const FIYAT_YETKI = 'satis.indirim_uygula';

    public function __construct(
        private KalemServisi $servis,
        private AdisyonOkumaServisi $adisyonServisi,
        private GuvenlikKapisi $kapi,
        private SalonSahipligi $sahiplik,
        private YetkiServisi $yetki,
        private MobilKalemServisi $mobilKalem,
        private PaketYukseltServisi $yukselt,
    ) {}

    /**
     * POST /api/v2/adisyon — YENİ SATIŞ (eski "Yeni Tahsilat" /yenitahsilat akışı).
     * body: {salon_id, musteri_id, tip, katalog_id, adet?, fiyat?, seans?, personel_id?, tarih?, idempotency_key}
     * Eskisiyle aynı: adisyon İLK KALEMLE açılır (boş adisyon oluşmaz); sonraki kalemler,
     * tahsilat ve alacak mevcut /adisyon/{id}/* uçlarıyla. Yetki: satis.adisyon_olustur.
     */
    public function adisyonAc(Request $request)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = (int) $request->input('salon_id');
        $musteriId = (int) $request->input('musteri_id');
        if (! $salonId || ! $musteriId) {
            return ApiCevap::hata('salon_id ve musteri_id zorunlu', 422);
        }
        $this->kapi->denetle($user->id, $salonId, self::DUZENLEME_YETKI);
        $this->sahiplik->musteriOlmali($musteriId, $salonId);
        [$tip, $katalogId, $opt] = $this->kalemGirdisi($request, $salonId, (int) $user->id);
        $tarih = $request->input('tarih') ? date('Y-m-d', strtotime((string) $request->input('tarih'))) : null;

        $sonuc = Idempotency::calistir('yeni_satis', (string) $request->input('idempotency_key'), (int) $user->id, $salonId,
            function () use ($salonId, $musteriId, $user, $tarih, $tip, $katalogId, $opt) {
                $adisyon = Adisyonlar::ac($salonId, $musteriId, (int) $user->id, $tarih);
                $kalem = $this->servis->ekle((int) $adisyon->id, $tip, $katalogId, $opt + ['tarih' => $tarih, 'kullanici_id' => (int) $user->id]);

                return ['adisyon_id' => (int) $adisyon->id, 'kalem_id' => $kalem['kalem_id'] ?? null];
            });

        return ApiCevap::basarili($sonuc, 'Satış açıldı.');
    }

    /** POST /api/v2/adisyon/{adisyon}/kalem  body: {tip, katalog_id, adet?, fiyat?, seans?, personel_id?, hizmetler? (paket)} */
    public function ekle(Request $request, int $adisyon)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->adisyonServisi->salonIdGetir($adisyon);
        if (! $salonId) {
            return ApiCevap::hata('Adisyon bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, self::DUZENLEME_YETKI);

        [$tip, $katalogId, $opt] = $this->kalemGirdisi($request, $salonId, (int) $user->id);

        $sonuc = $this->servis->ekle($adisyon, $tip, $katalogId, $opt + ['kullanici_id' => (int) $user->id]);
        $sonuc['hesap'] = $this->adisyonServisi->getir($adisyon)['hesap'];

        return ApiCevap::basarili($sonuc, 'Kalem eklendi.');
    }

    /** PATCH /api/v2/kalem/{tip}/{id}/fiyat  body: {fiyat} — elle fiyat (ürün: satır toplamı). */
    public function fiyat(Request $request, string $tip, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->servis->kalemSalonId($tip, $id);
        if (! $salonId) {
            return ApiCevap::hata('Kalem bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, [self::FIYAT_YETKI]);

        $sonuc = $this->servis->fiyatGuncelle($tip, $id, $this->fiyatGirdisi($request) ?? throw new GecersizIslemException('fiyat zorunlu'), (int) $user->id);
        $sonuc['hesap'] = $this->adisyonServisi->getir($sonuc['adisyon_id'])['hesap'];

        return ApiCevap::basarili($sonuc, 'Fiyat güncellendi.');
    }

    /** PATCH /api/v2/kalem/hizmet/{id}/seans  body: {seans} — boş/≤1 tekil hizmet, 2+ paket satışı. */
    public function seans(Request $request, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->servis->kalemSalonId('hizmet', $id);
        if (! $salonId) {
            return ApiCevap::hata('Kalem bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, self::DUZENLEME_YETKI);

        return ApiCevap::basarili($this->servis->hizmetSeansGuncelle($id, $request->input('seans')), 'Seans güncellendi.');
    }

    /** PATCH /api/v2/kalem/{tip}/{id}/personel  body: {personel_id} — hizmet veren / satıcı değiştir. */
    public function personel(Request $request, string $tip, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->servis->kalemSalonId($tip, $id);
        if (! $salonId) {
            return ApiCevap::hata('Kalem bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, self::DUZENLEME_YETKI);

        return ApiCevap::basarili(
            $this->servis->personelGuncelle($tip, $id, $this->personelGirdisi($request, $tip, $salonId)),
            'Personel güncellendi.'
        );
    }

    /**
     * GET /api/v2/kalem/paket/{id}/yukseltme-adaylari — satılmış paketi kapsayan (üst küme, en az 1 ek hizmet)
     * aktif katalog paketleri + mevcut paket ödenen. Web ve mobil aynı uç. Yetki: satis.adisyon_olustur.
     */
    public function yukseltmeAdaylari(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->servis->kalemSalonId('paket', $id);
        if (! $salonId) {
            return ApiCevap::hata('Kalem bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, self::DUZENLEME_YETKI);

        return ApiCevap::basarili($this->yukselt->adaylar($id, $salonId));
    }

    /**
     * POST /api/v2/kalem/paket/{id}/yukselt  body: {hedef_paket_id, fiyat?, idempotency_key}
     * Yerinde dönüştürme (seans ve tahsilat kayıtları korunur). Katalogdan farklı fiyat satis.indirim_uygula ister.
     */
    public function paketYukselt(Request $request, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $salonId = $this->servis->kalemSalonId('paket', $id);
        if (! $salonId) {
            return ApiCevap::hata('Kalem bulunamadı', 404);
        }
        $this->kapi->denetle($user->id, $salonId, self::DUZENLEME_YETKI);
        $hedef = $request->input('hedef_paket_id');
        if (! is_numeric($hedef) || (int) $hedef < 1) {
            throw new GecersizIslemException('hedef_paket_id zorunlu');
        }

        $sonuc = $this->yukselt->yukselt($id, $salonId, (int) $hedef, $this->fiyatGirdisi($request), [
            'yetkili_id'           => (int) $user->id,
            'fiyat_degistirebilir' => $this->yetki->yetkiliYetkiVar((int) $user->id, $salonId, self::FIYAT_YETKI),
        ], (string) $request->input('idempotency_key'));
        $sonuc['hesap'] = $this->adisyonServisi->getir($sonuc['adisyon_id'])['hesap'];

        return ApiCevap::basarili($sonuc, 'Paket yükseltildi. Kalan ödemeyi tahsil edebilirsiniz.');
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

        $sonuc = $this->servis->urunAdetGuncelle($id, $adet, (int) $user->id);
        $sonuc['hesap'] = $this->adisyonServisi->getir($sonuc['adisyon_id'])['hesap'];

        return ApiCevap::basarili($sonuc, 'Adet güncellendi.');
    }

    /** DELETE /api/v2/kalem/{tip}/{id} */
    public function sil(Request $request, int|string $tip, int $id)
    {
        $user = $this->kullanici();
        $this->yazmaAcikMi();

        $tip = (string) $tip;
        $salonId = $this->servis->kalemSalonId($tip, $id);
        if (! $salonId) {
            return ApiCevap::hata('Kalem bulunamadı', 404);
        }
        // Mobil: eski tahsilat_*_sil sonucu; yetki istegi eski mobilde etkisizdi, kapsam zorunlu.
        if ($request->boolean('mobil')) {
            $this->kapi->kapsamDenetle($user->id, $salonId);

            return ApiCevap::basarili($this->mobilKalem->sil($tip, $id, $salonId, (int) $user->id));
        }
        $this->kapi->denetle($user->id, $salonId, self::DUZENLEME_YETKI);

        $sonuc = $this->servis->sil($tip, $id, (int) $user->id);
        $sonuc['hesap'] = $this->adisyonServisi->getir($sonuc['adisyon_id'])['hesap'];

        return ApiCevap::basarili($sonuc, 'Kalem silindi.');
    }



    /**
     * Kalem ekleme girdisi — yeni satış ve mevcut adisyona ekleme AYNI kuralla:
     * tip ∈ hizmet|urun|paket, katalog_id zorunlu, personel kuralı personelGirdisi'nde;
     * katalogdan farklı fiyat yalnız satis.indirim_uygula ile.
     *
     * @return array{0:string,1:int,2:array<string,mixed>}
     */
    private function kalemGirdisi(Request $request, int $salonId, int $yetkiliId): array
    {
        $tip = (string) $request->input('tip');
        if (! in_array($tip, ['hizmet', 'urun', 'paket'], true)) {
            throw new GecersizIslemException('Geçersiz tip (hizmet|urun|paket)');
        }
        $katalogId = (int) $request->input('katalog_id');
        if (! $katalogId) {
            throw new GecersizIslemException('katalog_id zorunlu');
        }

        $opt = ['adet' => $request->input('adet'), 'personel_id' => $this->personelGirdisi($request, $tip, $salonId)];
        if ($tip === 'hizmet') {
            $opt['seans'] = $request->input('seans'); // opsiyonel; 2+ → paket satışı
        }
        if ($tip === 'paket') {
            // Paket: seçilen hizmetler (katalogdan farklıysa satışa özel kopya) + bölge başına seans (boş = katalog varsayılanı).
            $opt['hizmetler'] = $this->hizmetlerGirdisi($request);
            $opt['seans'] = $this->seansGirdisi($request);
        }
        $fiyat = $this->fiyatGirdisi($request);
        if ($fiyat !== null) {
            if ($fiyat !== $this->servis->katalogFiyati($tip, $katalogId, $salonId, (int) $request->input('adet', 1), $opt['hizmetler'] ?? null)
                && ! $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, self::FIYAT_YETKI)) {
                throw new YetkisizErisimException('Fiyatı değiştirme yetkiniz yok');
            }
            $opt['fiyat'] = $fiyat;
        }

        return [$tip, $katalogId, $opt];
    }

    /** Paket hizmet seçimi: tam sayı dizisi (en çok 50); yoksa null (katalog listesi). */
    private function hizmetlerGirdisi(Request $request): ?array
    {
        $h = $request->input('hizmetler');
        if ($h === null || $h === '' || $h === []) {
            return null;
        }
        if (is_string($h)) {
            $h = json_decode($h, true);
        }
        if (! is_array($h) || count($h) > 50) {
            throw new GecersizIslemException('Geçersiz hizmet listesi');
        }
        foreach ($h as $id) {
            if (! is_numeric($id) || (int) $id < 1) {
                throw new GecersizIslemException('Geçersiz hizmet listesi');
            }
        }

        return array_values(array_unique(array_map('intval', $h)));
    }

    /** Paket seansı (opsiyonel): 0–1000 tam sayı; boşsa null. */
    private function seansGirdisi(Request $request): ?int
    {
        $s = $request->input('seans');
        if ($s === null || $s === '') {
            return null;
        }
        if (! is_numeric($s) || (int) $s != $s || (int) $s < 0 || (int) $s > 1000) {
            throw new GecersizIslemException('Geçersiz seans sayısı');
        }

        return (int) $s;
    }

    /** İstekteki fiyat (yoksa null); sayı ve negatif olmayan olmalı. */
    private function fiyatGirdisi(Request $request): ?float
    {
        $fiyat = $request->input('fiyat');
        if ($fiyat === null || $fiyat === '') {
            return null;
        }
        if (! is_numeric($fiyat) || (float) $fiyat < 0) {
            throw new GecersizIslemException('Geçersiz fiyat');
        }

        return round((float) $fiyat, 2);
    }

    /** Hizmette personel zorunlu (stüdyo hariç), ürün/pakette opsiyonel; verilen personel bu salonun olmalı. */
    private function personelGirdisi(Request $request, string $tip, int $salonId): ?int
    {
        $personelId = (int) $request->input('personel_id') ?: null;
        if (! $personelId && $tip === 'hizmet' && Salonlar::hizmetPersonelZorunlu($salonId)) {
            throw new GecersizIslemException('Hizmet için personel seçiniz.');
        }
        $this->sahiplik->personelOlmali($personelId, $salonId);

        return $personelId;
    }
}
