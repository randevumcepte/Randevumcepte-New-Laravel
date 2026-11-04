<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Oturum\OturumServisi;
use App\Services\Oturum\SifreSifirlamaServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Giris + sifre sifirlama + cikis; web paneli ve Flutter ayni uclari kullanir. docs/strangler-oturum.md
 *
 *   POST /oturum/giris {kimlik, password, hesap_turu?, app_bundle?, cihaz_bilgi?, bildirim_id?}
 *        → {token, token_type, expires_at, user_type, user, musteri, ilk_sayfa}
 *   POST /oturum/sifre-kodu {cep_telefon, hesap_turu?}
 *   POST /oturum/sifre-sifirla {cep_telefon, kod, yeni_sifre, hesap_turu?}
 *   POST /oturum/cikis (Bearer)
 */
class OturumController extends V2Controller
{
    private const HESAP_TURU = ['nullable', 'in:isletme,musteri'];

    public function giris(Request $request, OturumServisi $servis)
    {
        $g = $this->alanlariDogrula($request, [
            'kimlik'      => ['required', 'string', 'max:190'],
            'password'    => ['required', 'string', 'max:200'],
            'hesap_turu'  => self::HESAP_TURU,
            'app_bundle'  => ['nullable', 'string', 'max:190'],
            'cihaz_bilgi' => ['nullable', 'string', 'max:500'],
            'bildirim_id' => ['nullable', 'string', 'max:255'],
        ]);

        return ApiCevap::basarili($servis->giris($g, (string) $request->ip()));
    }

    public function sifreKodu(Request $request, SifreSifirlamaServisi $servis)
    {
        $g = $this->alanlariDogrula($request, ['cep_telefon' => ['required', 'string', 'max:30'], 'hesap_turu' => self::HESAP_TURU]);
        $servis->kodGonder($g['cep_telefon'], $g['hesap_turu'] ?? null, (string) $request->ip());

        return ApiCevap::basarili(null, 'Numaranız kayıtlıysa doğrulama kodu SMS ile gönderildi.');
    }

    public function sifreSifirla(Request $request, SifreSifirlamaServisi $servis)
    {
        $g = $this->alanlariDogrula($request, [
            'cep_telefon' => ['required', 'string', 'max:30'],
            'kod'         => ['required', 'string', 'max:10'],
            'yeni_sifre'  => ['required', 'string', 'min:'.(int) config('oturum.sifre_min'), 'max:200'],
            'hesap_turu'  => self::HESAP_TURU,
        ]);
        $servis->sifirla($g['cep_telefon'], $g['kod'], $g['yeni_sifre'], $g['hesap_turu'] ?? null);

        return ApiCevap::basarili(null, 'Şifreniz güncellendi. Yeni şifrenizle giriş yapabilirsiniz.');
    }

    public function cikis(Request $request, OturumServisi $servis)
    {
        $servis->cikis($request->attributes->get('oauth_access_token_id'));

        return ApiCevap::basarili();
    }
}
