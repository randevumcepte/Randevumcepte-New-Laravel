<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Personel\PersonelArsivServisi;
use App\Services\Personel\PersonelListeServisi;
use App\Services\Personel\PersonelOkumaServisi;
use App\Services\Personel\PersonelYazServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Personel API v2 controller (ince). Is mantigi servislerde; burada yalnizca
 * kimlik/kapsam/yetki denetimi + servis cagrisi + standart ApiCevap zarfi.
 *
 * Faz 1 — SALT OKUMA (liste, goster, calisma/mola saatleri).
 * Faz 2 — YAZMA (ekle/duzenle, durum, siralama, takvim, saat, arsivle) config
 *         personel.yazma_aktif kill-switch arkasinda; kapaliyken 409.
 */
class PersonelController extends Controller
{
    public function __construct(
        private PersonelListeServisi $liste,
        private PersonelOkumaServisi $okuma,
        private PersonelYazServisi $yaz,
        private PersonelArsivServisi $arsiv,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
    ) {}

    /** GET /api/v2/personel?salon_id=&baslik=&per_page=  (personel.liste_gor) */
    public function liste(Request $request)
    {
        $user = $this->kullanici();
        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        $this->kapi->denetle($user->id, $salonId, ['personel.liste_gor']);

        $perPage = (int) $request->input('per_page', 10);
        $perPage = max(1, min(100, $perPage));

        return ApiCevap::basarili($this->liste->listele(
            $salonId,
            $request->input('baslik'),
            $this->maasGor($user->id, $salonId),
            $perPage,
        ));
    }

    /** GET /api/v2/personel/{id}  (personel.liste_gor) */
    public function goster(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->salonVeyaBulunamadi($id);

        $this->kapi->denetle($user->id, $salonId, ['personel.liste_gor']);

        return ApiCevap::basarili($this->okuma->getir($id, $this->maasGor($user->id, $salonId)));
    }

    /** GET /api/v2/personel/{id}/calisma-saatleri  (personel.liste_gor) */
    public function calismaSaatleri(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->salonVeyaBulunamadi($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.liste_gor']);

        return ApiCevap::basarili($this->okuma->calismaSaatleri($id));
    }

    /** GET /api/v2/personel/{id}/mola-saatleri  (personel.liste_gor) */
    public function molaSaatleri(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->salonVeyaBulunamadi($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.liste_gor']);

        return ApiCevap::basarili($this->okuma->molaSaatleri($id));
    }

    /** GET /api/v2/personel/{id}/gelecek-hizmetler  (personel.sil — arsiv akisi, salt-okuma) */
    public function gelecekHizmetler(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->salonVeyaBulunamadi($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.sil']);

        return ApiCevap::basarili($this->arsiv->gelecekHizmetler($salonId, $id));
    }

    // ===================== YAZMA (Faz 2, kill-switch) =====================

    /** POST /api/v2/personel  |  PATCH /api/v2/personel/{id}  (personel.ekle_duzenle) */
    public function ekleDuzenle(Request $request, ?int $id = null)
    {
        $user = $this->kullanici();

        if ($id !== null) {
            $salonId = $this->salonVeyaBulunamadi($id);
        } else {
            $salonId = (int) $request->input('salon_id');
            if (! $salonId) {
                return ApiCevap::hata('salon_id zorunlu', 422);
            }
        }

        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);
        if ($kapali = $this->yazmaKapali()) {
            return $kapali;
        }

        $g = [
            'salon_id'          => $salonId,
            'personel_id'       => $id,
            'personel_adi'      => $request->input('personel_adi'),
            'unvan'             => $request->input('unvan'),
            'cinsiyet'          => $request->input('cinsiyet'),
            'cep_telefon'       => $request->input('cep_telefon'),
            'maas'              => $request->input('maas'),
            'hizmet_prim_yuzde' => $request->input('hizmet_prim_yuzde'),
            'urun_prim_yuzde'   => $request->input('urun_prim_yuzde'),
            'paket_prim_yuzde'  => $request->input('paket_prim_yuzde'),
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
        $salonId = $this->salonVeyaBulunamadi($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);
        if ($kapali = $this->yazmaKapali()) {
            return $kapali;
        }

        return ApiCevap::basarili($this->yaz->durum($id, $request->boolean('aktif')));
    }

    /** PATCH /api/v2/personel/{id}/siralama  body: {delta:-1|1}  (personel.ekle_duzenle) */
    public function siralama(Request $request, int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->salonVeyaBulunamadi($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);
        if ($kapali = $this->yazmaKapali()) {
            return $kapali;
        }

        return ApiCevap::basarili($this->yaz->siralama($salonId, $id, (int) $request->input('delta')));
    }

    /** PATCH /api/v2/personel/{id}/takvim-gorunur  (personel.ekle_duzenle) */
    public function takvimToggle(int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->salonVeyaBulunamadi($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);
        if ($kapali = $this->yazmaKapali()) {
            return $kapali;
        }

        return ApiCevap::basarili($this->yaz->takvimToggle($id));
    }

    /** PATCH /api/v2/personel/{id}/saatler  body: {calisma_saatleri[], mola_saatleri[]}  (personel.ekle_duzenle) */
    public function saatKaydet(Request $request, int $id)
    {
        $user = $this->kullanici();
        $salonId = $this->salonVeyaBulunamadi($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.ekle_duzenle']);
        if ($kapali = $this->yazmaKapali()) {
            return $kapali;
        }

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
        $salonId = $this->salonVeyaBulunamadi($id);
        $this->kapi->denetle($user->id, $salonId, ['personel.sil']);
        if ($kapali = $this->yazmaKapali()) {
            return $kapali;
        }

        return ApiCevap::basarili($this->arsiv->arsivle($salonId, $id, $request->input('transferler')));
    }

    // ---------------------------------------------------------------------

    private function kullanici()
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        return $user;
    }

    /** Personelin salonunu coz; kayit yoksa kapsam sizintisini onlemek icin 404. */
    private function salonVeyaBulunamadi(int $id): int
    {
        $salonId = $this->okuma->salonIdGetir($id);
        if (! $salonId) {
            abort(404, 'Personel bulunamadı');
        }

        return $salonId;
    }

    private function maasGor($yetkiliId, int $salonId): bool
    {
        return $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'personel.maas_tutar_gor');
    }

    /** Yazma kill-switch kapaliysa 409 ApiCevap doner, aciksa null. */
    private function yazmaKapali()
    {
        if (! config('personel.yazma_aktif')) {
            return ApiCevap::hata('Personel yazma henüz etkin değil (PERSONEL_V2_YAZMA_AKTIF).', 409);
        }

        return null;
    }
}
