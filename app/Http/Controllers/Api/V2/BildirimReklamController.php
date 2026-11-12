<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\BildirimReklam\ReklamDurumu;
use App\Exceptions\GecersizIslemException;
use App\Http\Requests\BildirimReklam\BildirimReklamDurumIstegi;
use App\Http\Requests\BildirimReklam\BildirimReklamKaydetIstegi;
use App\Services\BildirimReklam\BildirimReklamFormServisi;
use App\Services\BildirimReklam\BildirimReklamSorgusu;
use App\Services\BildirimReklam\BildirimReklamYazServisi;
use App\Services\BildirimReklam\ReklamGonderServisi;
use App\Services\BildirimReklam\ReklamGorselServisi;
use App\Services\BildirimReklam\ReklamHedefKitlesi;
use App\Services\Randevu\RandevuFormServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * BİLDİRİM REKLAMLARI v2 — yönetim (eski /isletmeyonetim/bildirim-reklam* + mobil v1
 * /reklam/admin/*). Web paneli (/v2/bildirim-reklamlari) ve Flutter yönetici AYNI uçları kullanır.
 *
 *   GET    /bildirim-reklam/form-verisi?salon_id=   sözlükler, hizmet/ürün, şubeler, varsayılanlar
 *   GET    /bildirim-reklam?salon_id=&durum= + ListeIstegi  → {data[], sayaclar{tumu,taslak,aktif,pasif,bitti}, sayfalama}
 *   GET    /bildirim-reklam/musteri-ara?salon_id=&q=  "belirli kişi" hedefi (telefon yetkiye göre maskeli)
 *   POST   /bildirim-reklam/gorsel  multipart gorsel | base64 gorsel  → {yol, url}
 *   POST   /bildirim-reklam                 PATCH /bildirim-reklam/{id}
 *   GET    /bildirim-reklam/{id}            DELETE /bildirim-reklam/{id}
 *   PATCH  /bildirim-reklam/{id}/durum {durum}
 *   GET    /bildirim-reklam/{id}/alici-sayisi      → {hedef, alici}  (gönder onayından önce)
 *   POST   /bildirim-reklam/{id}/gonder            → {alici, kuyrukta}  (outbox → eski FCM kodu)
 *
 * Yetki: tümü pazarlama.kampanya_yonet (eski menü anahtarı; eski web uçları yalnız oturuma
 * bakıyordu). Açık telefon musteri.telefon_gor. Yazmalar BILDIRIM_REKLAM_V2_YAZMA_AKTIF arkasında.
 */
class BildirimReklamController extends V2Controller
{
    private const YETKI = BildirimReklamYazServisi::YETKI;

    protected ?string $yazmaAlani = 'bildirim_reklam';

    protected string $yazmaKapaliMesaj = 'Bildirim reklamı v2 yazma şu an kapalı';

    public function __construct(private BildirimReklamSorgusu $sorgu) {}

    public function formVerisi(Request $request, BildirimReklamFormServisi $form)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);

        return ApiCevap::basarili($form->formVerisi($salonId, $uid, (bool) config('bildirim_reklam.yazma_aktif')));
    }

    public function liste(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $filtre = $request->validate(['durum' => ['nullable', 'string', 'max:15']]);
        $liste = ListeIstegi::istekten($request, 50, array_keys(BildirimReklamSorgusu::SIRALAMA), 'id', 'desc')->dizi();

        return ApiCevap::basarili($this->sorgu->listele($salonId, $liste, $filtre, $this->telGor($uid, $salonId)));
    }

    public function goster(int $id)
    {
        [$uid, $salonId] = $this->reklamKaydi($id);

        return ApiCevap::basarili($this->sorgu->getir($id, $this->telGor($uid, $salonId)));
    }

    public function musteriAra(Request $request, RandevuFormServisi $form)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);

        return ApiCevap::basarili($form->musteriAra($salonId, (string) $request->input('q', ''), $this->telGor($uid, $salonId)));
    }

    public function gorsel(Request $request, ReklamGorselServisi $gorsel)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $this->yazmaAcikMi();
        $veri = $request->file('gorsel') ?? $request->input('gorsel');
        if (! $veri) {
            throw new GecersizIslemException('Görsel yok.');
        }

        return ApiCevap::basarili($gorsel->yukle($salonId, $uid, $veri), 'Görsel yüklendi', 201);
    }

    public function ekle(BildirimReklamKaydetIstegi $request, BildirimReklamYazServisi $yaz)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $this->yazmaAcikMi();
        $id = $yaz->kaydet($salonId, null, $request->validated(), $uid);

        return ApiCevap::basarili($this->sorgu->getir($id, $this->telGor($uid, $salonId)), 'Reklam kaydedildi', 201);
    }

    public function guncelle(BildirimReklamKaydetIstegi $request, BildirimReklamYazServisi $yaz, int $id)
    {
        [$uid, $salonId] = $this->reklamKaydi($id);
        $this->yazmaAcikMi();
        $yaz->kaydet($salonId, $id, $request->validated(), $uid);

        return ApiCevap::basarili($this->sorgu->getir($id, $this->telGor($uid, $salonId)), 'Reklam kaydedildi');
    }

    public function durum(BildirimReklamDurumIstegi $request, BildirimReklamYazServisi $yaz, int $id)
    {
        [$uid, $salonId] = $this->reklamKaydi($id);
        $this->yazmaAcikMi();
        $durum = ReklamDurumu::from($request->validated()['durum']);
        $yaz->durumDegistir($id, $durum, $uid);

        return ApiCevap::basarili($this->sorgu->getir($id, $this->telGor($uid, $salonId)), $durum === ReklamDurumu::AKTIF ? 'Reklam yayında' : 'Reklam durumu: '.$durum->etiket());
    }

    public function sil(BildirimReklamYazServisi $yaz, int $id)
    {
        [$uid] = $this->reklamKaydi($id);
        $this->yazmaAcikMi();
        $yaz->sil($id, $uid);

        return ApiCevap::basarili(['id' => $id], 'Reklam silindi');
    }

    public function aliciSayisi(ReklamHedefKitlesi $hedef, int $id)
    {
        $this->reklamKaydi($id);

        return ApiCevap::basarili($hedef->sayim($this->sorgu->satir($id)));
    }

    public function gonder(ReklamGonderServisi $gonderici, int $id)
    {
        [$uid] = $this->reklamKaydi($id);
        $this->yazmaAcikMi();
        $sonuc = $gonderici->gonder($id, $uid);

        return ApiCevap::basarili($sonuc, ReklamGonderServisi::mesaj($sonuc));
    }

    // ───────────────────────────── yardımcılar ─────────────────────────────

    private function telGor(int $uid, int $salonId): bool
    {
        return $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor');
    }

    /**
     * Yol parametresindeki reklamın salonu (yoksa 404) → kapsam + yetki.
     *
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    private function reklamKaydi(int $id): array
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu($this->sorgu->salonId($id), 'Reklam bulunamadı');
        app(GuvenlikKapisi::class)->denetle($uid, $salonId, self::YETKI);

        return [$uid, $salonId];
    }
}
