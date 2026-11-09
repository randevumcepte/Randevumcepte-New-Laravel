<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Musteri\SaglikIstegi;
use App\Services\MusteriHesap\HesapSilmeTalebiServisi;
use App\Services\MusteriHesap\MusteriOzetServisi;
use App\Services\MusteriHesap\MusteriProfilServisi;
use App\Services\MusteriHesap\MusteriSeansServisi;
use App\Services\Musteri\MusteriYazServisi;
use App\Services\Oturum\Hesap\MusteriHesabi;
use App\Services\MusteriHesap\MusteriRandevuServisi;
use App\Services\Oturum\ErisimTokenServisi;
use App\Services\Oturum\MobilBayraklar;
use App\Services\MusteriHesap\MusteriYorumServisi;
use App\Services\Online\OnlineRandevuServisi;
use App\Services\Randevu\RandevuFormServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Musteri paneli + randevu olusturma ('auth:api'). Kimlik yalniz token'dan.
 *
 *   GET    /musteri-uygulama/ben?tam=&app_bundle= PATCH /ben   POST|DELETE /ben/resim   POST /ben/sifre   POST /ben/cikis
 *   PATCH  /musteri-uygulama/ben/saglik           GET /ozet?app_bundle=   POST /hesap-silme-talebi
 *   GET    /musteri-uygulama/seanslar?app_bundle=&salon_id=&page=
 *   GET    /musteri-uygulama/randevular?salon_id=&durum=&page=
 *   POST   /musteri-uygulama/randevular           {salon_id, tarih, saat, kalemler[], not?, kaynak, idempotency_key?}
 *   POST   /musteri-uygulama/randevular/{id}/iptal
 *   GET    /musteri-uygulama/paketlerim?salon_id=
 *   POST   /musteri-uygulama/yorumlar             {salon_id, puan, yorum}
 *   POST   /musteri-uygulama/yorumlar/{id}/bildir {sebep}
 */
class MusteriHesapController extends MusteriUygulamaController
{
    public function __construct(private MusteriProfilServisi $profil, private MusteriRandevuServisi $randevu) {}

    /** tam=1: uygulama acilisi; profil + saglik alanlari + marka subeleri (giris cevabindaki musteri ile ayni). */
    public function ben(Request $request, MusteriHesabi $hesap)
    {
        // Kimlik numarasi musteri ekranlarinda okunmaz, disari verilmez.
        return ApiCevap::basarili($request->boolean('tam')
            ? Arr::except($hesap->acilisVerisi($this->musteriId(), $request->input('app_bundle') ?: null)['musteri'], ['tc_kimlik_no'])
            : $this->profil->goster($this->musteriId()));
    }

    public function ozet(Request $request, MusteriOzetServisi $ozet)
    {
        $g = $request->validate(['app_bundle' => ['required', 'string', 'max:190']]);

        return ApiCevap::basarili($ozet->ozet($this->musteriId(), $g['app_bundle']));
    }

    /** Musteri kendi saglik bilgisini yazar; portfoy ve personel yetkisi bu yolda aranmaz, hedef yalniz token sahibidir. */
    public function saglik(SaglikIstegi $request, MusteriYazServisi $yaz)
    {
        $id = $this->musteriId();
        $salonId = (int) DB::table('musteri_portfoy')->where('user_id', $id)->orderByDesc('aktif')->orderByDesc('id')->value('salon_id');

        return ApiCevap::basarili($yaz->saglikKaydet($salonId, $id, $request->validated(), null), 'Sağlık bilgileri kaydedildi.');
    }

    public function hesapSilmeTalebi(HesapSilmeTalebiServisi $talep)
    {
        $talep->gonder($this->musteriId());

        return ApiCevap::basarili(null, 'Hesap silme talebiniz alındı.');
    }

    public function seanslar(Request $request, MusteriSeansServisi $seans)
    {
        return ApiCevap::basarili($seans->liste($this->musteriId(), $request->input('app_bundle') ?: null, $request->integer('salon_id') ?: null, max(1, $request->integer('page'))));
    }

    public function guncelle(Request $request)
    {
        $g = $request->validate([
            'name'         => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'email'        => ['sometimes', 'nullable', 'email', 'max:150'],
            'ev_telefon'   => ['sometimes', 'nullable', 'string', 'max:30'],
            'meslek'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'cinsiyet'     => ['sometimes', 'nullable', 'in:0,1'],
            'dogum_tarihi' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'adres'        => ['sometimes', 'nullable', 'string', 'max:500'],
            'cep_telefon'  => ['sometimes', 'required', 'string', 'max:30'],
            'password'     => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        if (! empty($g['password'])) {
            $this->profil->sifreAta($this->musteriId(), $g['password'], $request->attributes->get('oauth_access_token_id'));
        }
        unset($g['password']);

        return ApiCevap::basarili($this->profil->guncelle($this->musteriId(), $g), 'Profiliniz güncellendi.');
    }

    public function resimYukle(Request $request)
    {
        return ApiCevap::basarili($this->profil->resimYukle($this->musteriId(), $request->file('resim')));
    }

    public function resimKaldir()
    {
        return ApiCevap::basarili($this->profil->resimKaldir($this->musteriId()));
    }

    public function sifreDegistir(Request $request)
    {
        $g = $request->validate(['mevcut_sifre' => ['required', 'string'], 'yeni_sifre' => ['required', 'string', 'max:200']]);
        $this->profil->sifreDegistir($this->musteriId(), $g['mevcut_sifre'], $g['yeni_sifre'], $request->attributes->get('oauth_access_token_id'));

        return ApiCevap::basarili(null, 'Şifreniz değiştirildi.');
    }

    public function cikis(Request $request, ErisimTokenServisi $token)
    {
        if ($id = $request->attributes->get('oauth_access_token_id')) {
            $token->iptal($id);
        }

        return ApiCevap::basarili();
    }

    public function randevular(Request $request)
    {
        $g = $request->validate([
            'salon_id' => ['nullable', 'integer'],
            'durum'    => ['nullable', 'integer', 'in:0,1,2,3'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'app_bundle' => ['nullable', 'string', 'max:190'],
            'tarih1'     => ['nullable', 'date_format:Y-m-d'],
            'tarih2'     => ['nullable', 'date_format:Y-m-d'],
            'kaynaklar'  => ['nullable', 'array'],
            'kaynaklar.*' => ['string', 'in:salon,web,uygulama'],
        ]);

        return ApiCevap::basarili($this->randevu->liste($this->musteriId(), $g['salon_id'] ?? null, isset($g['durum']) ? (int) $g['durum'] : null, (int) ($g['per_page'] ?? 20), $g, MobilBayraklar::mobilToken($request)));
    }

    public function randevuOlustur(Request $request, OnlineRandevuServisi $servis)
    {
        $g = $request->validate([
            'salon_id'                     => ['required', 'integer'],
            'tarih'                        => ['required', 'date_format:Y-m-d'],
            'saat'                         => ['required', 'date_format:H:i'],
            'kalemler'                     => ['required', 'array', 'min:1', 'max:20'],
            'kalemler.*.hizmet_id'         => ['required', 'integer'],
            'kalemler.*.personel_id'       => ['nullable', 'integer'],
            'kalemler.*.adisyon_paket_id'  => ['nullable', 'integer'],
            'kalemler.*.adisyon_hizmet_id' => ['nullable', 'integer'],
            'not'                          => ['nullable', 'string', 'max:1000'],
            'kaynak'                       => ['required', 'in:web,uygulama'],
            'idempotency_key'              => ['nullable', 'string', 'max:64'],
            'mobil'                        => ['nullable', 'boolean'],
            '_hp'                          => ['nullable', 'string', 'max:200'],
            'form_sure_sn'                 => ['nullable', 'integer'],
        ]);

        // Kimligi dogrulanmis musteri: IP siniri yok, musteri basina salon basina gunluk sinir (OnlineRandevuSiniri).
        return ApiCevap::basarili($servis->olustur((int) $g['salon_id'], $this->musteriId(), $g), 'Randevu talebiniz alındı.', 201);
    }

    public function randevuIptal(int $id)
    {
        return ApiCevap::basarili($this->randevu->iptal($this->musteriId(), $id), 'Randevunuz iptal edildi.');
    }

    public function paketlerim(Request $request, RandevuFormServisi $form)
    {
        $request->validate(['salon_id' => ['required', 'integer']]);

        return ApiCevap::basarili($form->musteriPaketleri($request->integer('salon_id'), $this->musteriId()));
    }

    public function yorumYap(Request $request, MusteriYorumServisi $yorum)
    {
        $g = $request->validate([
            'salon_id' => ['required', 'integer'],
            'puan'     => ['required', 'integer', 'between:1,5'],
            'yorum'    => [$request->boolean('mobil') ? 'nullable' : 'required', 'string', 'max:2000'],
        ]);
        $yorum->kaydet($this->musteriId(), (int) $g['salon_id'], (int) $g['puan'], trim((string) ($g['yorum'] ?? '')), $request->boolean('mobil'));

        return ApiCevap::basarili(null, 'Yorumunuz kaydedildi.');
    }

    public function yorumBildir(Request $request, MusteriYorumServisi $yorum, int $id)
    {
        $g = $request->validate(['sebep' => ['required', 'string', 'max:200']]);
        $yorum->bildir($this->musteriId(), $id, trim($g['sebep']), $request->boolean('mobil'));

        return ApiCevap::basarili(null, 'Bildiriminiz alındı.');
    }
}
