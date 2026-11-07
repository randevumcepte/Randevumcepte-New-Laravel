<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\MusteriHesap\MusteriOturumServisi;
use App\Services\MusteriHesap\MusteriTokenYenilemeServisi;
use App\Services\Oturum\SifreSifirlamaServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Musteri girisi / hizli kayit / sifre sifirlama — kimliksiz uclar (throttle arkasinda).
 *
 *   POST /musteri-oturum/giris {cep_telefon, password, salon_id?}          → {token, user_type, musteri}
 *   POST /musteri-oturum/kayit {ad_soyad, cep_telefon, kvkk, salon_id, _hp?, form_sure_sn?}
 *        → {sifre_gerekli:true} ya da {sifre_gerekli:false, token, user_type, musteri}
 *   POST /musteri-oturum/sifre-kodu {cep_telefon, salon_id}
 *   POST /musteri-oturum/sifre-sifirla {cep_telefon, salon_id, kod, yeni_sifre}
 *   POST /musteri-oturum/kayit {sifre_gonder:true, app_bundle?, ...}      → {kayitli} (uygulama: sifre SMS ile gider; kayitli numarada hicbir sey degismez)
 *   POST /musteri-oturum/sifre-gonder {cep_telefon, app_bundle?}          → {bulundu} (uygulama: tek adimda yeni sifre; yalniz musteri hesabi)
 *   POST /musteri-oturum/yenile (Bearer, suresi dolmus olabilir)          → {token|null, expires_at} (yalniz imzali musteri token'i)
 */
class MusteriOturumController extends Controller
{
    public function __construct(private MusteriOturumServisi $servis) {}

    public function giris(Request $request)
    {
        $g = $request->validate([
            'cep_telefon' => ['required', 'string', 'max:30'],
            'password'    => ['required', 'string', 'max:200'],
            'salon_id'    => ['nullable', 'integer'],
        ]);

        return ApiCevap::basarili($this->servis->giris($g['cep_telefon'], $g['password'], $g['salon_id'] ?? null, (string) $request->ip()));
    }

    public function kayit(Request $request)
    {
        $g = $request->validate([
            'ad_soyad'     => ['required', 'string', 'min:2', 'max:100'],
            'cep_telefon'  => ['required', 'string', 'max:30'],
            'kvkk'         => ['accepted'],
            'salon_id'     => ['required', 'integer'],
            '_hp'          => ['nullable', 'string'],
            'form_sure_sn' => ['nullable', 'integer'],
            'app_bundle'   => ['nullable', 'string', 'max:190'],
        ]);

        return ApiCevap::basarili($request->boolean('sifre_gonder')
            ? $this->servis->sifreyleKayit($g, (string) $request->ip())
            : $this->servis->kayit($g, (string) $request->ip()));
    }

    public function sifreGonder(Request $request, SifreSifirlamaServisi $sifre)
    {
        $g = $request->validate(['cep_telefon' => ['required', 'string', 'max:30'], 'app_bundle' => ['nullable', 'string', 'max:190']]);

        return ApiCevap::basarili(['bulundu' => $sifre->yeniSifreGonder($g['cep_telefon'], $g['app_bundle'] ?? null, (string) $request->ip())]);
    }

    public function yenile(Request $request, MusteriTokenYenilemeServisi $yenileme)
    {
        return ApiCevap::basarili($yenileme->yenile($request->bearerToken()));
    }

    public function sifreKodu(Request $request)
    {
        $g = $request->validate(['cep_telefon' => ['required', 'string', 'max:30'], 'salon_id' => ['required', 'integer']]);
        $this->servis->sifreKoduGonder($g['cep_telefon'], (int) $g['salon_id'], (string) $request->ip());

        return ApiCevap::basarili(null, 'Numaranız kayıtlıysa doğrulama kodu SMS ile gönderildi.');
    }

    public function sifreSifirla(Request $request)
    {
        $g = $request->validate([
            'cep_telefon' => ['required', 'string', 'max:30'],
            'salon_id'    => ['required', 'integer'],
            'kod'         => ['required', 'string', 'max:10'],
            'yeni_sifre'  => ['required', 'string', 'min:6', 'max:200'],
        ]);
        $this->servis->sifreSifirla($g['cep_telefon'], (int) $g['salon_id'], $g['kod'], $g['yeni_sifre']);

        return ApiCevap::basarili(null, 'Şifreniz güncellendi. Yeni şifrenizle giriş yapabilirsiniz.');
    }
}
