<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Models\Adisyonlar;
use App\Models\Salonlar;
use App\Services\Satis\KalemServisi;
use App\Services\Tahsilat\AdisyonOkumaServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\SalonSahipligi;
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

    public function __construct(
        private KalemServisi $servis,
        private AdisyonOkumaServisi $adisyonServisi,
        private GuvenlikKapisi $kapi,
        private SalonSahipligi $sahiplik,
    ) {}

    /**
     * POST /api/v2/adisyon — YENİ SATIŞ (eski "Yeni Tahsilat" /yenitahsilat akışı).
     * body: {salon_id, musteri_id, tip, katalog_id, adet?, personel_id?, tarih?, idempotency_key}
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
        [$tip, $katalogId, $opt] = $this->kalemGirdisi($request, $salonId);
        $tarih = $request->input('tarih') ? date('Y-m-d', strtotime((string) $request->input('tarih'))) : null;

        $sonuc = Idempotency::calistir('yeni_satis', (string) $request->input('idempotency_key'), (int) $user->id, $salonId,
            function () use ($salonId, $musteriId, $user, $tarih, $tip, $katalogId, $opt) {
                $adisyon = Adisyonlar::ac($salonId, $musteriId, (int) $user->id, $tarih);
                $kalem = $this->servis->ekle((int) $adisyon->id, $tip, $katalogId, $opt + ['tarih' => $tarih, 'kullanici_id' => (int) $user->id]);

                return ['adisyon_id' => (int) $adisyon->id, 'kalem_id' => $kalem['kalem_id'] ?? null];
            });

        return ApiCevap::basarili($sonuc, 'Satış açıldı.');
    }

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

        [$tip, $katalogId, $opt] = $this->kalemGirdisi($request, $salonId);

        $sonuc = $this->servis->ekle($adisyon, $tip, $katalogId, $opt + ['kullanici_id' => (int) $user->id]);
        $sonuc['hesap'] = $this->adisyonServisi->getir($adisyon)['hesap'];

        return ApiCevap::basarili($sonuc, 'Kalem eklendi.');
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

        $sonuc = $this->servis->sil($tip, $id, (int) $user->id);
        $sonuc['hesap'] = $this->adisyonServisi->getir($sonuc['adisyon_id'])['hesap'];

        return ApiCevap::basarili($sonuc, 'Kalem silindi.');
    }



    /**
     * Kalem ekleme girdisi — yeni satış ve mevcut adisyona ekleme AYNI kuralla:
     * tip ∈ hizmet|urun|paket, katalog_id zorunlu, personel kuralı personelGirdisi'nde.
     *
     * @return array{0:string,1:int,2:array{adet:mixed,personel_id:?int}}
     */
    private function kalemGirdisi(Request $request, int $salonId): array
    {
        $tip = (string) $request->input('tip');
        if (! in_array($tip, ['hizmet', 'urun', 'paket'], true)) {
            throw new GecersizIslemException('Geçersiz tip (hizmet|urun|paket)');
        }
        $katalogId = (int) $request->input('katalog_id');
        if (! $katalogId) {
            throw new GecersizIslemException('katalog_id zorunlu');
        }

        return [$tip, $katalogId, ['adet' => $request->input('adet'), 'personel_id' => $this->personelGirdisi($request, $tip, $salonId)]];
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
