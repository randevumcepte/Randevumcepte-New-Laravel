<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Kampanya\HedefKitle;
use App\Domain\Kampanya\Indirim;
use App\Domain\Kampanya\KalemSecimi;
use App\Domain\Kampanya\Kanal;
use App\Domain\Kampanya\SablonKimligi;
use App\Http\Requests\Kampanya\KampanyaKaydetIstegi;
use App\Http\Requests\Kampanya\KampanyaOnizlemeIstegi;
use App\Services\Kampanya\HedefKitleServisi;
use App\Services\Kampanya\KampanyaFormServisi;
use App\Services\Kampanya\KampanyaMesajServisi;
use App\Services\Kampanya\KampanyaSorgusu;
use App\Services\Kampanya\KampanyaYazServisi;
use App\Services\Randevu\RandevuFormServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * REKLAM YÖNETİMİ v2 — kampanya (eski /isletmeyonetim/kampanya_yonetimi + v1 kampanya* uçları).
 *
 *   GET    /kampanya/form-verisi?salon_id=          kanal/hedef/cinsiyet/grup/katalog/şablon/yer tutucu/indirim/planlama/sekme/senaryo sözlükleri
 *   GET    /kampanya?salon_id=&kanal= + ListeIstegi → {data[], current_page, last_page, per_page, total, sayaclar{tumu,1,2,3,4}}
 *   GET    /kampanya/{id}                           detay + düzenleme alanları (v1 kampanyaDuzenleGetir + kampanyaDetay başlığı)
 *   POST   /kampanya/kitle-sayisi                   {salon_id, hedef_*} → {toplam, etiket}
 *   POST   /kampanya/mesaj-onizle                   {salon_id, gorev_turu, sablon_id|mesaj, hizmet_urun_paket, indirim_*}
 *                                                   → {sablon_metni, kayit_metni, onizleme, ornek_musteri, karakter}
 *   POST   /kampanya   PATCH /kampanya/{id}         sihirbaz kaydı → {id, katilimci_sayisi, katilimcilar_yenilendi, kampanya}
 *   DELETE /kampanya/{id}                           pasif yap (eski "Sil")
 *   GET    /kampanya/musteri-ara?salon_id=&q=       katılımcı eklemek için müşteri arama (randevu formuyla ortak)
 */
class KampanyaController extends KampanyaTabanController
{
    public function __construct(private KampanyaSorgusu $sorgu) {}

    public function formVerisi(Request $request, KampanyaFormServisi $form)
    {
        [$uid, $salonId] = $this->salon($request);

        return ApiCevap::basarili($form->formVerisi($salonId, [
            'ekle_duzenle' => (bool) config('kampanya.yazma_aktif'),
            'telefon_gor'  => $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor'),
        ]));
    }

    public function liste(Request $request)
    {
        [$uid, $salonId] = $this->salon($request);
        $kanal = Kanal::degerden($request->input('kanal'));
        $liste = ListeIstegi::istekten($request, 25, array_keys(KampanyaSorgusu::SIRALAMA), 'id', 'desc')->dizi();

        return ApiCevap::basarili($this->sorgu->listele($salonId, $liste, $kanal, $this->ctx($uid, $salonId)));
    }

    public function goster(int $id)
    {
        [$uid, $salonId] = $this->kampanya($id);

        return ApiCevap::basarili($this->sorgu->getir($id, $this->ctx($uid, $salonId)));
    }

    public function kitleSayisi(KampanyaOnizlemeIstegi $request, HedefKitleServisi $kitle)
    {
        [$uid, $salonId] = $this->salon($request);
        $v = $request->validated();
        $h = HedefKitle::olustur($v['hedef_filtre'] ?? '', $v['hedef_grup'] ?? '', $v['hedef_cinsiyet'] ?? '');

        return ApiCevap::basarili([
            'toplam' => $kitle->sayi($salonId, $h, $this->kisitPersonel($uid, $salonId)),
            'etiket' => $kitle->etiket($salonId, $h),
        ]);
    }

    public function mesajOnizle(KampanyaOnizlemeIstegi $request, KampanyaMesajServisi $mesaj)
    {
        [, $salonId] = $this->salon($request);
        $v = $request->validated();
        $kanal = Kanal::degerden($v['gorev_turu'] ?? null) ?? Kanal::Sms;
        $indirim = $kanal->indirimli() ? Indirim::girdiden($v) : null;

        return ApiCevap::basarili($mesaj->onizle($salonId, $kanal, SablonKimligi::coz($v['sablon_id'] ?? null), $v['mesaj'] ?? null,
            $indirim, KalemSecimi::coz($v['hizmet_urun_paket'] ?? null)));
    }

    public function ekle(KampanyaKaydetIstegi $request, KampanyaYazServisi $yaz)
    {
        [$uid, $salonId] = $this->salon($request);
        $this->yazmaAcikMi();
        $s = $yaz->kaydet($salonId, null, $request->validated(), $uid, $this->kisitPersonel($uid, $salonId));

        return ApiCevap::basarili($s + ['kampanya' => $this->sorgu->getir($s['id'], $this->ctx($uid, $salonId))],
            'Kampanya oluşturuldu ('.$s['katilimci_sayisi'].' katılımcı)', 201);
    }

    public function guncelle(KampanyaKaydetIstegi $request, KampanyaYazServisi $yaz, int $id)
    {
        [$uid, $salonId] = $this->kampanya($id);
        $this->yazmaAcikMi();
        $s = $yaz->kaydet($salonId, $id, $request->validated(), $uid, $this->kisitPersonel($uid, $salonId));

        return ApiCevap::basarili($s + ['kampanya' => $this->sorgu->getir($id, $this->ctx($uid, $salonId))],
            $s['katilimcilar_yenilendi'] ? 'Kampanya güncellendi, katılımcı listesi yenilendi' : 'Kampanya güncellendi');
    }

    public function sil(KampanyaYazServisi $yaz, int $id)
    {
        [$uid, $salonId] = $this->kampanya($id);
        $this->yazmaAcikMi();
        $yaz->pasifYap($salonId, $id, $uid);

        return ApiCevap::basarili(['id' => $id], 'Kampanya silindi');
    }

    public function musteriAra(Request $request, RandevuFormServisi $form)
    {
        [$uid, $salonId] = $this->salon($request);

        return ApiCevap::basarili($form->musteriAra($salonId, (string) $request->input('q', ''), $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor')));
    }
}
