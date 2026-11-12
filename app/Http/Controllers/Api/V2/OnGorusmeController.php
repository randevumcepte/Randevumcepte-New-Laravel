<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\OnGorusme\OnGorusmeKaydetIstegi;
use App\Http\Requests\OnGorusme\OnGorusmeSatisIstegi;
use App\Http\Requests\OnGorusme\OnGorusmeSatisYapilmadiIstegi;
use App\Services\Musteri\PortfoyKapsami;
use App\Services\OnGorusme\OnGorusmeFormServisi;
use App\Services\OnGorusme\OnGorusmeSonucServisi;
use App\Services\OnGorusme\OnGorusmeSorgusu;
use App\Services\OnGorusme\OnGorusmeYazServisi;
use App\Services\Randevu\RandevuFormServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * ÖN GÖRÜŞME v2 (eski /isletmeyonetim/ongorusmeler + mobil v1 ongorusme* uçları) — ince controller.
 *
 *   GET  /ongorusme/form-verisi?salon_id=        neden kataloğu, personel/oda, sözlükler, yetkiler
 *   GET  /ongorusme?salon_id=&durum=&tarih_bas=&tarih_bit=&personel_id= + ListeIstegi
 *        → {data[], current_page, last_page, per_page, total, sayaclar{tumu, beklemede, satis_yapildi, satis_yapilmadi}}
 *   GET  /ongorusme/{id}
 *   GET  /ongorusme/musteri-ara?salon_id=&q=
 *   POST /ongorusme   PATCH /ongorusme/{id}      (çakışma = iş sonucu: basarili + veri.cakismavar='1';
 *                                                 "yine de kaydet" için cakisanrandevuekle=1)
 *   POST /ongorusme/{id}/satis-yapildi   {tarih?, personel_id?, fiyat?, seans?, adet?} → {adisyon_id, ...}
 *   POST /ongorusme/{id}/satis-yapilmadi {not}
 *
 * Yetki (eski menü/sayfa anahtarları): okuma gorusme.liste_gor · yazma/sonuç gorusme.ekle_duzenle ·
 * katalogdan farklı satış fiyatı satis.indirim_uygula · açık telefon musteri.telefon_gor.
 * Personel rolündeki kullanıcı yalnız kendi görüşmelerini görür / işler (OnGorusmeSorgusu).
 * Yazmalar ONGORUSME_V2_YAZMA_AKTIF arkasında (kapalıyken 409) — kapsam/yetki SONRASI denetlenir.
 */
class OnGorusmeController extends V2Controller
{
    private const OKU = 'gorusme.liste_gor';

    /** Görüşme yazma — mobil ön görüşme satışı (TahsilatMobilController) da bu anahtarı ister. */
    public const YAZ = 'gorusme.ekle_duzenle';

    protected ?string $yazmaAlani = 'ongorusme';

    protected string $yazmaKapaliMesaj = 'Ön görüşme v2 yazma şu an kapalı';

    public function __construct(
        private OnGorusmeSorgusu $sorgu,
        private PortfoyKapsami $portfoy,
    ) {}

    public function formVerisi(Request $request, OnGorusmeFormServisi $form)
    {
        [$uid, $salonId] = $this->salonDenetle($request, [self::OKU, self::YAZ]);

        return ApiCevap::basarili($form->formVerisi($salonId, $this->portfoy->personelId($uid, $salonId), [
            'ekle_duzenle'   => $this->yetkiVar($uid, $salonId, self::YAZ),
            'fiyat_degistir' => $this->yetkiVar($uid, $salonId, 'satis.indirim_uygula'),
            'telefon_gor'    => $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor'),
        ]));
    }

    public function liste(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::OKU);
        $filtre = $request->validate([
            'durum'       => ['nullable', 'string', 'max:30'],
            'tarih_bas'   => ['nullable', 'date_format:Y-m-d'],
            'tarih_bit'   => ['nullable', 'date_format:Y-m-d'],
            'personel_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $liste = ListeIstegi::istekten($request, 25, array_keys(OnGorusmeSorgusu::SIRALAMA), 'created_at', 'desc')->dizi();

        return ApiCevap::basarili($this->sorgu->listele($salonId, $liste, $filtre, $this->ctx($uid, $salonId)));
    }

    public function goster(int $id)
    {
        [$uid, $salonId] = $this->gorusmeDenetle($id, self::OKU);

        return ApiCevap::basarili($this->sorgu->getir($id, $this->ctx($uid, $salonId)));
    }

    public function musteriAra(Request $request, RandevuFormServisi $form)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YAZ);

        // detay: secilen musterinin email / cinsiyet / referansi formu on-doldurur.
        return ApiCevap::basarili($form->musteriAra($salonId, (string) $request->input('q', ''), $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor'), true));
    }

    public function ekle(OnGorusmeKaydetIstegi $request, OnGorusmeYazServisi $yaz)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YAZ);
        $this->yazmaAcikMi();

        return $this->kayitCevabi($yaz->kaydet($salonId, null, $request->validated(), $uid), $uid, $salonId, 'Ön görüşme başarıyla kaydedildi', 201);
    }

    public function guncelle(OnGorusmeKaydetIstegi $request, OnGorusmeYazServisi $yaz, int $id)
    {
        [$uid, $salonId] = $this->gorusmeDenetle($id, self::YAZ);
        $this->yazmaAcikMi();

        return $this->kayitCevabi($yaz->kaydet($salonId, $id, $request->validated(), $uid), $uid, $salonId, 'Ön görüşme başarıyla güncellendi');
    }

    public function satisYapildi(OnGorusmeSatisIstegi $request, OnGorusmeSonucServisi $sonuc, int $id)
    {
        [$uid, $salonId] = $this->gorusmeDenetle($id, self::YAZ);
        $this->yazmaAcikMi();

        $veri = $sonuc->satisYapildi($salonId, $id, $request->validated(), [
            'yetkili_id'           => $uid,
            'personel_id'          => $this->portfoy->personelId($uid, $salonId),
            'fiyat_degistirebilir' => $this->yetkiVar($uid, $salonId, 'satis.indirim_uygula'),
        ]);

        return ApiCevap::basarili($veri + ['on_gorusme' => $this->sorgu->getir($id, $this->ctx($uid, $salonId))], 'Satış oluşturuldu', 201);
    }

    public function satisYapilmadi(OnGorusmeSatisYapilmadiIstegi $request, OnGorusmeSonucServisi $sonuc, int $id)
    {
        [$uid, $salonId] = $this->gorusmeDenetle($id, self::YAZ);
        $this->yazmaAcikMi();
        $sonuc->satisYapilmadi($salonId, $id, (string) ($request->validated()['not'] ?? ''), $uid);

        return ApiCevap::basarili($this->sorgu->getir($id, $this->ctx($uid, $salonId)), 'Ön görüşme durumu SATIŞ YAPILMADI olarak güncellendi');
    }

    // ───────────────────────────── yardımcılar ─────────────────────────────

    /** Kayıt cevabı: çakışma iş sonucudur (200 + cakismavar='1'); başarıda güncel görüşme eklenir. */
    private function kayitCevabi(array $s, int $uid, int $salonId, string $mesaj, int $kod = 200)
    {
        if ($s['cakismavar'] === '1') {
            return ApiCevap::basarili($s, 'Çakışma var');
        }

        return ApiCevap::basarili($s + ['on_gorusme' => $this->sorgu->getir($s['id'], $this->ctx($uid, $salonId))], $mesaj, $kod);
    }

    /** @return array{kisitli_personel:?int, telefon_gor:bool, yazabilir:bool} */
    private function ctx(int $uid, int $salonId): array
    {
        return [
            'kisitli_personel' => $this->sorgu->kisitliPersonel($uid, $salonId),
            'telefon_gor'      => $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor'),
            'yazabilir'        => $this->yetkiVar($uid, $salonId, self::YAZ) && (bool) config('ongorusme.yazma_aktif'),
        ];
    }

    /**
     * Yol parametresindeki görüşmenin salonu (yoksa 404) → kapsam + yetki; personel rolündeki
     * kullanıcı başkasının görüşmesine erişemez (404 — varlık sızdırılmaz).
     *
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    private function gorusmeDenetle(int $id, array|string $yetki): array
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu($this->sorgu->salonId($id), 'Ön görüşme bulunamadı');
        app(GuvenlikKapisi::class)->denetle($uid, $salonId, $yetki);
        $this->sorgu->erisimZorunlu($id, $uid, $salonId);

        return [$uid, $salonId];
    }
}
