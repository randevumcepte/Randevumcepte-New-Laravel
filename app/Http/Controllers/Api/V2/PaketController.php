<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Paket\PaketKaydetIstegi;
use App\Http\Requests\Paket\PaketSatisIstegi;
use App\Models\Personeller;
use App\Models\Salonlar;
use App\Services\Paket\PaketMusteriKontrolu;
use App\Services\Paket\PaketSatisServisi;
use App\Services\Paket\PaketSorgusu;
use App\Services\Paket\PaketYazServisi;
use App\Services\Randevu\RandevuFormServisi;
use App\Services\Satis\KatalogServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\Idempotency;
use App\Support\ListeIstegi;
use App\Support\Yetki\IslemYetkileri;
use Illuminate\Http\Request;

/**
 * PAKET YONETIMI v2 (eski /isletmeyonetim/paketsatislari + mobil v1 paket uclari) — ince controller.
 *
 *   GET    /paket/form-verisi?salon_id=        hizmet secenekleri + yetkiler + studyo/yazma bayraklari
 *   GET    /paket?salon_id=&tumu= + ListeIstegi (page, per_page, ara, sirala, yon)
 *   GET    /paket/{id}
 *   POST   /paket  {salon_id, paket_adi, hizmetler, miktar?, fiyat?, sure?}   PATCH /paket/{id}   DELETE /paket/{id} (pasif)
 *   GET    /paket/musteri-ara?salon_id=&q=
 *   POST   /paket/satis {salon_id, musteri_id, personel_id?, tarih?, kalemler[{paket_id, seans?, fiyat?}], idempotency_key}
 *   GET    /paket/musteri-kontrol?salon_id=&user_id=&onay_var=   randevu formu kalan seans popup'i (mobil)
 *
 * mobil=1 (yalniz imzali mobil token, MobilBayraklar): eski mobil uclarin ham liste + yazma kurali (cevap bicimi);
 * yetki web ile AYNI anahtarlar (2026-11-12). Yeni kayit idempotency_key ister.
 *
 * Yetki (eski menu/sayfa + satis kapisiyla ayni anahtarlar):
 *   okuma : paket.tanim_olustur | paket.sat     tanim yaz : paket.tanim_olustur
 *   satis : paket.sat (katalog disi fiyat + satis.indirim_uygula)
 * Yazmalar PAKET_V2_YAZMA_AKTIF arkasinda (kapaliyken 409) — kapsam/yetki SONRASI denetlenir.
 */
class PaketController extends V2Controller
{
    private const OKU = ['paket.tanim_olustur', 'paket.sat'];

    private const TANIM = 'paket.tanim_olustur';

    protected ?string $yazmaAlani = 'paket';

    protected string $yazmaKapaliMesaj = 'Paket v2 yazma şu an kapalı';

    public function __construct(
        private PaketSorgusu $sorgu,
        private PaketYazServisi $yaz,
    ) {}

    public function formVerisi(Request $request, KatalogServisi $katalog)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::OKU);

        return ApiCevap::basarili([
            'hizmetler'   => $katalog->hizmetler($salonId),
            'studyo_modu' => Salonlar::studyoModu($salonId), // fiyat gizleme (MODEL B)
            'yetkiler'    => [
                'tanim_olustur'  => $this->yetkiVar($uid, $salonId, 'paket.tanim_olustur'),
                'sat'            => $this->yetkiVar($uid, $salonId, 'paket.sat'),
                'fiyat_degistir' => $this->yetkiVar($uid, $salonId, 'satis.indirim_uygula'),
            ],
            'yazma_aktif' => (bool) config('paket.yazma_aktif'),
        ]);
    }

    public function liste(Request $request)
    {
        [, $salonId] = $this->salonDenetle($request, self::OKU);
        if ($request->boolean('mobil')) {
            return ApiCevap::basarili($request->boolean('tumu')
                ? $this->sorgu->mobilKatalog($salonId, (string) $request->input('yon'))
                : $this->sorgu->mobilSayfa($salonId, (string) $request->input('arama', ''), max(1, $request->integer('page', 1))));
        }

        $liste = ListeIstegi::istekten($request, 25, PaketSorgusu::SIRALANABILIR, 'id', 'desc')->dizi();

        return ApiCevap::basarili($this->sorgu->listele($salonId, $liste, $request->boolean('tumu')));
    }

    public function goster(int $id)
    {
        $this->paketDenetle($id, self::OKU);

        return ApiCevap::basarili($this->sorgu->getir($id));
    }

    public function ekle(PaketKaydetIstegi $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::TANIM);
        $this->yazmaAcikMi();
        if ($request->boolean('mobil')) {
            $sonuc = Idempotency::calistir('paket_mobil_ekle', (string) $request->input('idempotency_key'), $uid, $salonId,
                fn () => ['id' => $this->yaz->mobilKaydet($salonId, null, $request->validated(), $uid)]);

            return ApiCevap::basarili($sonuc, 'Paket başarıyla eklendi', 201);
        }

        return ApiCevap::basarili($this->yaz->kaydet($salonId, null, $request->validated(), $uid), 'Paket başarıyla eklendi', 201);
    }

    public function guncelle(PaketKaydetIstegi $request, int $id)
    {
        [$uid, $salonId] = $this->paketDenetle($id, self::TANIM);
        $this->yazmaAcikMi();
        if ($request->boolean('mobil')) {
            return ApiCevap::basarili(['id' => $this->yaz->mobilKaydet($salonId, $id, $request->validated(), $uid)], 'Paket başarıyla güncellendi');
        }

        return ApiCevap::basarili($this->yaz->kaydet($salonId, $id, $request->validated(), $uid), 'Paket başarıyla güncellendi');
    }

    public function sil(int $id)
    {
        [$uid] = $this->paketDenetle($id, self::TANIM);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->yaz->sil($id, $uid), 'Paket başarıyla kaldırıldı');
    }

    public function musteriKontrol(Request $request, PaketMusteriKontrolu $kontrol)
    {
        [, $salonId] = $this->salonDenetle($request, IslemYetkileri::RANDEVU_FORM);

        return ApiCevap::basarili($kontrol->getir($salonId, $request->integer('user_id'), $request->boolean('onay_var')));
    }

    public function musteriAra(Request $request, RandevuFormServisi $form)
    {
        [$uid, $salonId] = $this->salonDenetle($request, 'paket.sat');

        return ApiCevap::basarili($form->musteriAra($salonId, (string) $request->input('q', ''), $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor')));
    }

    public function sat(PaketSatisIstegi $request, PaketSatisServisi $satis)
    {
        [$uid, $salonId] = $this->salonDenetle($request, 'paket.sat');
        $this->yazmaAcikMi();

        return ApiCevap::basarili($satis->sat($salonId, $request->validated(), [
            'yetkili_id'           => $uid,
            'personel_id'          => Personeller::where('salon_id', $salonId)->where('yetkili_id', $uid)->value('id'),
            'fiyat_degistirebilir' => $this->yetkiVar($uid, $salonId, 'satis.indirim_uygula'),
        ]), 'Paket satışı oluşturuldu', 201);
    }

    /**
     * Yol parametresindeki paketin salonu (yoksa 404) → kapsam + yetki.
     *
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    private function paketDenetle(int $id, array|string $yetki): array
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu($this->sorgu->salonId($id), 'Paket bulunamadı');
        app(GuvenlikKapisi::class)->denetle($uid, $salonId, $yetki);

        return [$uid, $salonId];
    }
}
