<?php

namespace App\Exceptions;

use App\Support\ApiCevap;
use Illuminate\Http\JsonResponse;

/**
 * Islem bir onay / dogrulama kodu girilmeden yapilamaz → HTTP 428. Web ve mobil AYNI sozlesmeyi okur:
 * {basarili:false, veri:{dogrulama_gerekli:true, kod_gonderildi}, mesaj}. Istemci kodu sorup ayni istegi
 * `onay_kodu` (silmede hesap sahibi onayi) ya da `dogrulama_kodu` (seans geldi) alaniyla tekrarlar.
 * Kendi render()'i oldugu icin bootstrap/app.php'deki genel ApiException cevirisinden once calisir.
 */
class OnayKoduGerekliException extends ApiException
{
    public function __construct(string $mesaj, public bool $kodGonderildi = true)
    {
        parent::__construct($mesaj, 428);
    }

    public function render(): JsonResponse
    {
        return ApiCevap::hata($this->getMessage(), $this->durumKodu, [
            'dogrulama_gerekli' => true,
            'kod_gonderildi'    => $this->kodGonderildi,
        ]);
    }
}
