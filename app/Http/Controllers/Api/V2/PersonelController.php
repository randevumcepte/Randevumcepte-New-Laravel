<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Personel\PersonelArsivServisi;
use App\Services\Personel\PersonelListeServisi;
use App\Services\Personel\PersonelOkumaServisi;
use App\Services\Personel\PersonelYazServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Personel API v2 controller (ince). Is mantigi servislerde; burada yalnizca
 * kimlik/kapsam/yetki denetimi + servis cagrisi + standart ApiCevap zarfi.
 *
 * Faz 1 — SALT OKUMA (liste, goster, calisma/mola saatleri).
 * Faz 2 — YAZMA (ekle/duzenle, durum, siralama, takvim, saat, arsivle) config
 *         personel.yazma_aktif kill-switch arkasinda; kapaliyken 409.
 */
class PersonelController extends V2Controller
{
    protected ?string $yazmaAlani = 'personel';

    public function __construct(
        private PersonelListeServisi $liste,
        private PersonelOkumaServisi $okuma,
        private PersonelYazServisi $yaz,
        private PersonelArsivServisi $arsiv,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
    ) {}

    /**
     * GET /api/v2/personel?salon_id=&baslik=&per_page=&tumu=
     *  - sayfali yonetim listesi            → personel.liste_gor
     *  - tumu=1 secici (randevu/satis/kasa) → salonun her uyesi (eski /personeller/{salon}
     *    yetkisizdi); liste_gor yoksa telefon/maas alanlari bos doner.
     * Her cevapta 'ozet' (toplam/aktif/takvimde) vardir.
     */
    public function liste(Request $request)
    {
        $user = $this->kullanici();
        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        $tumu = $request->boolean('tumu');
        $listeGor = $this->yetki->yetkiliYetkiVar($user->id, $salonId, 'personel.liste_gor');
        if ($tumu && ! $listeGor) {
            $this->kapi->kapsamDenetle($user->id, $salonId);
        } else {
            $this->kapi->denetle($user->id, $salonId, ['personel.liste_gor']);
        }

        // Ortak tablo sözleşmesi (ara/baslik, per_page, page) — ListeIstegi.
        $liste = ListeIstegi::istekten($request, 10);

        return ApiCevap::basarili($this->liste->listele(
            $salonId,
            $liste->ara,
            $this->gorunurluk($user->id, $salonId, $listeGor),
            $liste->sayfaBoyu,
            $tumu,
        ));
    }

    /** GET /api/v2/personel/form-verisi?salon_id= — hesap turleri + cinsiyetler (UI'da sabit liste yok) */
    public function formVerisi(Request $request)
    {
        $user = $this->kullanici();
        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }
        $this->kapi->denetle($user->id, $salonId, ['personel.liste_gor', 'personel.ekle_duzenle']);

        return ApiCevap::basarili($this->okuma->formVerisi());
    }

    /** GET /api/v2/personel/{id}  (personel.liste_gor) */
    public function goster(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->okuma->salonIdZorunlu($id);

        $this->kapi->denetle($user->id, $salonId, ['personel.liste_gor']);

        return ApiCevap::basarili($this->okuma->getir($id, $this->gorunurluk($user->id, $salonId, true)));
    }

    /** GET /api/v2/personel/{id}/calisma-saatleri  (personel.liste_gor) */
    public function calismaSaatleri(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->okuma->salonIdZorunlu($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.liste_gor']);

        return ApiCevap::basarili($this->okuma->calismaSaatleri($id));
    }

    /** GET /api/v2/personel/{id}/mola-saatleri  (personel.liste_gor) */
    public function molaSaatleri(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->okuma->salonIdZorunlu($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.liste_gor']);

        return ApiCevap::basarili($this->okuma->molaSaatleri($id));
    }

    /** GET /api/v2/personel/{id}/prim-kalemleri  (personel.ekle_duzenle — kaleme göre prim form verisi) */
    public function primKalemleri(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->okuma->salonIdZorunlu($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);

        return ApiCevap::basarili($this->okuma->primKalemleri($id, $salonId));
    }

    /** GET /api/v2/personel/{id}/gelecek-hizmetler  (personel.sil — arsiv akisi, salt-okuma) */
    public function gelecekHizmetler(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->okuma->salonIdZorunlu($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.sil']);

        return ApiCevap::basarili($this->arsiv->gelecekHizmetler($salonId, $id));
    }

    // ===================== YAZMA (Faz 2, kill-switch) =====================

    /** POST /api/v2/personel  |  PATCH /api/v2/personel/{id}  (personel.ekle_duzenle) */
    public function ekleDuzenle(Request $request, ?int $id = null)
    {
        $user = $this->kullanici();

        if ($id !== null) {
            $salonId = $this->okuma->salonIdZorunlu($id);
        } else {
            $salonId = (int) $request->input('salon_id');
            if (! $salonId) {
                return ApiCevap::hata('salon_id zorunlu', 422);
            }
        }

        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);
        $this->yazmaAcikMi();

        $g = [
            'salon_id'          => $salonId,
            'personel_id'       => $id,
            'yapan_id'          => $user->id,
            'personel_adi'      => $request->input('personel_adi'),
            'unvan'             => $request->input('unvan'),
            'cinsiyet'          => $request->input('cinsiyet'),
            'cep_telefon'       => $request->input('cep_telefon'),
            'maas'              => $request->input('maas'),
            'hizmet_prim_yuzde' => $request->input('hizmet_prim_yuzde'),
            'urun_prim_yuzde'   => $request->input('urun_prim_yuzde'),
            'paket_prim_yuzde'  => $request->input('paket_prim_yuzde'),
            'hizmet_prim_detayli' => $request->boolean('hizmet_prim_detayli'),
            'urun_prim_detayli'   => $request->boolean('urun_prim_detayli'),
            'paket_prim_detayli'  => $request->boolean('paket_prim_detayli'),
            'prim_kalemleri'    => (array) $request->input('prim_kalemleri', []),
            'sistem_yetki'      => $request->input('sistem_yetki'),
            'calisma_saatleri'  => (array) $request->input('calisma_saatleri', []),
            'mola_saatleri'     => (array) $request->input('mola_saatleri', []),
        ];

        return ApiCevap::basarili(
            $this->yaz->ekleDuzenle($g),
            $id !== null ? 'Personel güncellendi.' : 'Personel eklendi.'
        );
    }

    /** PATCH /api/v2/personel/{id}/durum  body: {aktif:bool}  (personel.ekle_duzenle) */
    public function durum(Request $request, int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->okuma->salonIdZorunlu($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->yaz->durum($id, $request->boolean('aktif'), $user->id));
    }

    /** POST /api/v2/personel/{id}/sifre-gonder — yeni sifre WhatsApp/SMS (kuyruk)  (personel.ekle_duzenle) */
    public function sifreGonder(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->okuma->salonIdZorunlu($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->yaz->sifreGonder($id, $user->id), 'Yeni şifre personele gönderiliyor.');
    }

    /** PATCH /api/v2/personel/{id}/siralama  body: {delta:-1|1}  (personel.ekle_duzenle) */
    public function siralama(Request $request, int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->okuma->salonIdZorunlu($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->yaz->siralama($salonId, $id, (int) $request->input('delta'), $user->id));
    }

    /** PATCH /api/v2/personel/{id}/takvim-gorunur  (personel.ekle_duzenle) */
    public function takvimToggle(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->okuma->salonIdZorunlu($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->yaz->takvimToggle($id, $user->id));
    }

    /** PATCH /api/v2/personel/{id}/saatler  body: {calisma_saatleri[], mola_saatleri[]}  (personel.ekle_duzenle) */
    public function saatKaydet(Request $request, int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->okuma->salonIdZorunlu($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->yaz->saatKaydet(
            $id,
            (array) $request->input('calisma_saatleri', []),
            (array) $request->input('mola_saatleri', []),
        ));
    }

    /** DELETE /api/v2/personel/{id}  body: {transferler}  (personel.sil — arsivle) */
    public function arsivle(Request $request, int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->okuma->salonIdZorunlu($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.sil']);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->arsiv->arsivle($salonId, $id, $request->input('transferler'), $user->id));
    }

    // ---------------------------------------------------------------------

    /** DTO hassas alan gorunurlugu (bkz. PersonelOkumaServisi::donustur). */
    private function gorunurluk($yetkiliId, int $salonId, bool $listeGor): array
    {
        return [
            'maas'    => $listeGor && $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'personel.maas_tutar_gor'),
            'telefon' => $listeGor,
        ];
    }
}
