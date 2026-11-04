<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Ayar\IsletmeBilgiServisi;
use App\Services\Deneme\DenemeKayitServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Ucretsiz deneme kaydi (kimliksiz; web sayfasi ve Flutter ayni uclar).
 *
 *   GET  /deneme/secenekler → {salon_turleri:[{id,ad}]}
 *   POST /deneme/kod-gonder {salon_turu_id, isletme_adi, isletme_adresi, ad_soyad, cep_telefon, email} → {sure_sn}
 *   POST /deneme/dogrula {cep_telefon, kod} → {salon_id, sifre_gonderildi, giris_yolu}
 */
class DenemeController extends V2Controller
{
    protected ?string $yazmaAlani = 'oturum.deneme';

    protected string $yazmaKapaliMesaj = 'Ücretsiz deneme kaydı şu anda kapalı';

    public function __construct(private DenemeKayitServisi $servis) {}

    public function secenekler(IsletmeBilgiServisi $isletme)
    {
        return ApiCevap::basarili(['salon_turleri' => $isletme->salonTurleri()]);
    }

    public function kodGonder(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, [
            'salon_turu_id'  => ['required', 'integer', 'exists:salon_turu,id'],
            'isletme_adi'    => ['required', 'string', 'max:120'],
            'isletme_adresi' => ['required', 'string', 'max:255'],
            'ad_soyad'       => ['required', 'string', 'max:100'],
            'cep_telefon'    => ['required', 'string', 'max:30'],
            'email'          => ['required', 'email', 'max:190'],
        ]);

        return ApiCevap::basarili(['sure_sn' => $this->servis->kodGonder($g, (string) $request->ip())], 'Telefonunuza gönderilen 4 haneli doğrulama kodunu giriniz.');
    }

    public function dogrulaVeKur(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, ['cep_telefon' => ['required', 'string', 'max:30'], 'kod' => ['required', 'string', 'max:10']]);
        $sonuc = $this->servis->dogrula($g['cep_telefon'], $g['kod']);

        return ApiCevap::basarili($sonuc + ['giris_yolu' => '/v2/giris'], $sonuc['sifre_gonderildi']
            ? 'Hesabınız oluşturuldu. Telefonunuza gönderilen şifreniz ile giriş yapabilirsiniz.'
            : 'Hesabınız oluşturuldu ancak şifre SMS\'i gönderilemedi. "Şifremi Unuttum" ile şifre belirleyebilirsiniz.');
    }
}
